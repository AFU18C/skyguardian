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
        title.setText("Mini 4K GPS Tool v2.6");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        Button neighbor = new Button(this);
        neighbor.setText("GNSS DEEP NEIGHBOR MAP v2.6 — READ ONLY");
        LinearLayout.LayoutParams p0 = new LinearLayout.LayoutParams(-1, -2);
        p0.topMargin = pad;
        root.addView(neighbor, p0);

        TextView note = new TextView(this);
        note.setText("v2.6 не угадывает имена. Читает все E1/E2 параметры вокруг подтверждённых GPS/FDI/service блоков Mini 4K и проверяет F7/F8 aliases. Никаких write/exec команд.");
        note.setTextSize(14);
        LinearLayout.LayoutParams pn = new LinearLayout.LayoutParams(-1, -2);
        pn.topMargin = pad;
        pn.bottomMargin = pad;
        root.addView(note, pn);

        Button hidden = new Button(this);
        hidden.setText("HIDDEN GPS HASH SCAN v2.5 — READ ONLY");
        root.addView(hidden, top(8));

        Button oneShot = new Button(this);
        oneShot.setText("ONE-SHOT FULL GNSS PARAM MAP v2.4 — READ ONLY");
        root.addView(oneShot, top(8));

        Button signal = new Button(this);
        signal.setText("GNSS SIGNAL / JAMMING / SPOOF ANALYZER — READ ONLY");
        root.addView(signal, top(8));

        TextView manual = new TextView(this);
        manual.setText("MANUAL CONTROLS — не запускать для диагностики");
        manual.setTextSize(15);
        LinearLayout.LayoutParams pm = new LinearLayout.LayoutParams(-1, -2);
        pm.topMargin = pad;
        root.addView(manual, pm);

        Button atti = new Button(this);
        atti.setText("ATTI ON SPORT / RESTORE");
        root.addView(atti, top(8));

        Button control = new Button(this);
        control.setText("GPS ON / OFF CONTROL");
        root.addView(control, top(8));

        setContentView(scroll);

        neighbor.setOnClickListener(v -> startActivity(new Intent(this, GnssNeighborhoodDumpActivity.class)));
        hidden.setOnClickListener(v -> startActivity(new Intent(this, HiddenGnssParamActivity.class)));
        oneShot.setOnClickListener(v -> startActivity(new Intent(this, GnssReceiverConfigScanActivity.class)));
        signal.setOnClickListener(v -> startActivity(new Intent(this, GnssSignalAnalyzerActivity.class)));
        atti.setOnClickListener(v -> startActivity(new Intent(this, AttiControlActivity.class)));
        control.setOnClickListener(v -> startActivity(new Intent(this, FinalActivity.class)));
    }

    private LinearLayout.LayoutParams top(int dp) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = Math.round(dp * getResources().getDisplayMetrics().density);
        return p;
    }
}
