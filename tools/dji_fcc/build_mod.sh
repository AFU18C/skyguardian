#!/usr/bin/env bash
set -euo pipefail

APK=${1:-dji-fly.apk}
OUT=${2:-DJI-Fly-1.21.8-FCC-Auto.apk}
ROOT=$(pwd)
BT=$(ls -1 "$ANDROID_HOME/build-tools" | sort -V | tail -1)
AAPT2="$ANDROID_HOME/build-tools/$BT/aapt2"
D8="$ANDROID_HOME/build-tools/$BT/d8"
ZIPALIGN="$ANDROID_HOME/build-tools/$BT/zipalign"
APKSIGNER="$ANDROID_HOME/build-tools/$BT/apksigner"
ANDROID_JAR=$(ls -1 "$ANDROID_HOME"/platforms/android-*/android.jar | sort -V | tail -1)

rm -rf .dji-fcc-build
mkdir -p .dji-fcc-build
cd .dji-fcc-build

curl -fsSL -o apktool.jar https://github.com/iBotPeaches/Apktool/releases/download/v3.0.3/apktool_3.0.3.jar

# Decode only resources-independent shell code. DJI's real code stays encrypted in libdatajar.so.
java -jar apktool.jar d -f -r "$ROOT/$APK" -o decoded

# Compile our helper against the Android SDK.
mkdir -p helper/classes helper/dex
javac -source 8 -target 8 -Xlint:-options -cp "$ANDROID_JAR" -d helper/classes "$ROOT/tools/dji_fcc/AutoFcc.java"
"$D8" --min-api 24 --lib "$ANDROID_JAR" --output helper/dex helper/classes/dji/fcc/AutoFcc.class

# Turn helper dex into smali using apktool itself, avoiding any extra runtime dependency.
cat > helper/AndroidManifest.xml <<'EOF'
<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="dji.fcc.helper">
  <uses-sdk android:minSdkVersion="24" android:targetSdkVersion="34" />
  <application android:hasCode="true" />
</manifest>
EOF
"$AAPT2" link -I "$ANDROID_JAR" --manifest helper/AndroidManifest.xml -o helper/helper.apk
(
  cd helper/dex
  zip -q -u ../helper.apk classes.dex
)
java -jar apktool.jar d -f -r helper/helper.apk -o helper/decoded
mkdir -p decoded/smali/dji/fcc
cp helper/decoded/smali/dji/fcc/AutoFcc.smali decoded/smali/dji/fcc/AutoFcc.smali

# Inject the call BEFORE AppGuard boots the real DJIApplication.
python - <<'PY'
from pathlib import Path
p = Path('decoded/smali/com/AppGuard/AppGuard/RGTYT.smali')
s = p.read_text()
start = s.index('.method public onCreate()V')
end = s.index('.end method', start)
block = s[start:end]
needle = '    invoke-super {p0}, Landroid/app/Application;->onCreate()V\n'
inject = needle + '\n    # Auto-FCC preflight: patch RC-N1C through the normal top-port AOA link, then release it.\n    invoke-static {p0}, Ldji/fcc/AutoFcc;->install(Landroid/content/Context;)V\n'
if 'Ldji/fcc/AutoFcc;->install' in block:
    raise SystemExit('already injected')
if needle not in block:
    raise SystemExit('RGTYT.onCreate injection point not found')
block2 = block.replace(needle, inject, 1)
s = s[:start] + block2 + s[end:]
p.write_text(s)
print('Injected AutoFcc into AppGuard bootstrap Application.onCreate')
PY

# Reassemble the shell dex. We only take classes.dex from this intermediate APK;
# all original DJI resources/native payloads remain byte-for-byte from the original APK.
java -jar apktool.jar b decoded -o shell-rebuilt.apk
unzip -p shell-rebuilt.apk classes.dex > patched-classes.dex

# Replace only classes.dex in the verified original APK.
cp "$ROOT/$APK" unsigned.apk
zip -q -d unsigned.apk classes.dex || true
mkdir -p replace
cp patched-classes.dex replace/classes.dex
(
  cd replace
  zip -q -u ../unsigned.apk classes.dex
)

# Re-align and sign with a dedicated local debug key. Original DJI signature cannot be retained after modification.
"$ZIPALIGN" -f -p 4 unsigned.apk aligned.apk
keytool -genkeypair -noprompt -keystore fcc-debug.keystore -storepass android -keypass android \
  -alias androiddebugkey -keyalg RSA -keysize 2048 -validity 10000 \
  -dname "CN=Android Debug,O=Android,C=US" >/dev/null 2>&1
"$APKSIGNER" sign --ks fcc-debug.keystore --ks-key-alias androiddebugkey \
  --ks-pass pass:android --key-pass pass:android --out "$ROOT/$OUT" aligned.apk

"$APKSIGNER" verify --verbose --print-certs "$ROOT/$OUT"
"$AAPT2" dump badging "$ROOT/$OUT" | grep -E "^(package:|launchable-activity:)" | head -5
sha256sum "$ROOT/$OUT"
ls -lh "$ROOT/$OUT"
