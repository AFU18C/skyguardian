package com.openai.mini4kgps;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

public class HomeActivity extends Activity {
    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        int pad = Math.round(16 * getResources().getDisplayMetrics().density);
        ScrollView scroll = new ScrollView(this);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);
        scroll.addView(root, new ScrollView.LayoutParams(-1, -2));

        TextView title = new TextView(this);
        title.setText("Mini 4K GPS Tool v2.0");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        Button chipProbe = new Button(this);
        chipProbe.setText("GNSS CHIP / BUS PROBE — READ ONLY");
        LinearLayout.LayoutParams pc = new LinearLayout.LayoutParams(-1, -2);
        pc.topMargin = pad;
        root.addView(chipProbe, pc);

        Button deepGnss = new Button(this);
        deepGnss.setText("DEEP GNSS RECEIVER CONFIG SCAN — READ ONLY");
        root.addView(deepGnss, top(8));

        Button gnssSignal = new Button(this);
        gnssSignal.setText("GNSS SIGNAL / SNR ANALYZER");
        root.addView(gnssSignal, top(8));

        Button full = new Button(this);
        full.setText("FULL GROUND DIAGNOSTICS — READ ONLY");
        root.addView(full, top(8));

        Button prop = new Button(this);
        prop.setText("MOTOR / PROPELLER TEST — READ ONLY");
        root.addView(prop, top(8));

        Button live = new Button(this);
        live.setText("PASSIVE LIVE FC STATUS — READ ONLY");
        root.addView(live, top(8));

        Button atti = new Button(this);
        atti.setText("ATTI ON SPORT / RESTORE");
        root.addView(atti, top(8));

        Button control = new Button(this);
        control.setText("GPS ON / OFF CONTROL");
        root.addView(control, top(8));

        Button gnssScan = new Button(this);
        gnssScan.setText("GNSS PARAM SCAN — READ ONLY");
        root.addView(gnssScan, top(8));

        Button modeScan = new Button(this);
        modeScan.setText("FLIGHT MODE SCAN — READ ONLY");
        root.addView(modeScan, top(8));

        TextView note = new TextView(this);
        note.setText("GNSS CHIP / BUS PROBE пытается определить, существует ли отдельный DUML GPS endpoint (DeviceType 26) и отдаёт ли он firmware/hardware identity. Только COMMON/Version read к GPS[0..2] + пассивный FLYC 0x57 GPS/GLNS и 0xA1 A-GPS status. Никаких receiver-config, E3/F9 или 0xDF команд записи.");
        note.setTextSize(14);
        LinearLayout.LayoutParams pn = new LinearLayout.LayoutParams(-1, -2);
        pn.topMargin = pad;
        pn.bottomMargin = pad;
        root.addView(note, pn);

        setContentView(scroll);

        chipProbe.setOnClickListener(v -> startActivity(new Intent(this, GnssChipProbeActivity.class)));
        deepGnss.setOnClickListener(v -> startActivity(new Intent(this, GnssReceiverConfigScanActivity.class)));
        gnssSignal.setOnClickListener(v -> startActivity(new Intent(this, GnssSignalAnalyzerActivity.class)));
        full.setOnClickListener(v -> startActivity(new Intent(this, FullGroundDiagnosticsActivity.class)));
        prop.setOnClickListener(v -> startActivity(new Intent(this, PropellerTestActivity.class)));
        live.setOnClickListener(v -> startActivity(new Intent(this, LiveFcStatusActivity.class)));
        atti.setOnClickListener(v -> startActivity(new Intent(this, AttiControlActivity.class)));
        control.setOnClickListener(v -> startActivity(new Intent(this, FinalActivity.class)));
        gnssScan.setOnClickListener(v -> startActivity(new Intent(this, GnssScanActivity.class)));
        modeScan.setOnClickListener(v -> startActivity(new Intent(this, FlightModeScanActivity.class)));
    }

    private LinearLayout.LayoutParams top(int dp) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = Math.round(dp * getResources().getDisplayMetrics().density);
        return p;
    }
}
