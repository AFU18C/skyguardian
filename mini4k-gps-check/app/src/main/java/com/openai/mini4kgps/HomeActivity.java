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
        title.setText("Mini 4K GPS Tool v1.8");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        Button gnssSignal = new Button(this);
        gnssSignal.setText("GNSS SIGNAL / SNR ANALYZER");
        LinearLayout.LayoutParams pg = new LinearLayout.LayoutParams(-1, -2);
        pg.topMargin = pad;
        root.addView(gnssSignal, pg);

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
        note.setText("GNSS SIGNAL / SNR ANALYZER включает только временную штатную выдачу SNR (FLYC 0x46 → push 0x45), а не усиливает RF-приёмник. Постоянные параметры FC/GNSS не меняет. Показывает GPS/GLONASS SNR, used channels, satellites/GPS level и доступные jamming/spoofing flags. Для анализа лучше открытое небо, моторы OFF. DJI Fly полностью закрыть; телефон — в верхний порт RC-N1.");
        note.setTextSize(14);
        LinearLayout.LayoutParams pn = new LinearLayout.LayoutParams(-1, -2);
        pn.topMargin = pad;
        pn.bottomMargin = pad;
        root.addView(note, pn);

        setContentView(scroll);

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
