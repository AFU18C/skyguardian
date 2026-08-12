package com.openai.mini4kgps;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

public class HomeActivity extends Activity {
    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        int pad = Math.round(16 * getResources().getDisplayMetrics().density);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K GPS Tool v1.4");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        Button live = new Button(this);
        live.setText("LIVE FC STATUS — READ ONLY");
        LinearLayout.LayoutParams pl = new LinearLayout.LayoutParams(-1, -2);
        pl.topMargin = pad;
        root.addView(live, pl);

        Button atti = new Button(this);
        atti.setText("ATTI ON SPORT / RESTORE");
        LinearLayout.LayoutParams pa = new LinearLayout.LayoutParams(-1, -2);
        pa.topMargin = Math.round(8 * getResources().getDisplayMetrics().density);
        root.addView(atti, pa);

        Button control = new Button(this);
        control.setText("GPS ON / OFF CONTROL");
        LinearLayout.LayoutParams p1 = new LinearLayout.LayoutParams(-1, -2);
        p1.topMargin = Math.round(8 * getResources().getDisplayMetrics().density);
        root.addView(control, p1);

        Button gnssScan = new Button(this);
        gnssScan.setText("GNSS SCAN — READ ONLY");
        LinearLayout.LayoutParams p2 = new LinearLayout.LayoutParams(-1, -2);
        p2.topMargin = Math.round(8 * getResources().getDisplayMetrics().density);
        root.addView(gnssScan, p2);

        Button modeScan = new Button(this);
        modeScan.setText("FLIGHT MODE SCAN — READ ONLY");
        LinearLayout.LayoutParams p3 = new LinearLayout.LayoutParams(-1, -2);
        p3.topMargin = Math.round(8 * getResources().getDisplayMetrics().density);
        root.addView(modeScan, p3);

        TextView note = new TextView(this);
        note.setText("LIVE FC STATUS ничего не записывает и показывает FC State / GPS Used / satellites / GPS State. Для проверки ATTI: моторы не запускать, START LIVE, затем переключить N → S. DJI Fly полностью закрыть; телефон подключить к верхнему порту RC-N1.");
        note.setTextSize(14);
        LinearLayout.LayoutParams pn = new LinearLayout.LayoutParams(-1, -2);
        pn.topMargin = pad;
        root.addView(note, pn);

        setContentView(root);

        live.setOnClickListener(v -> startActivity(new Intent(this, LiveFcStatusActivity.class)));
        atti.setOnClickListener(v -> startActivity(new Intent(this, AttiControlActivity.class)));
        control.setOnClickListener(v -> startActivity(new Intent(this, FinalActivity.class)));
        gnssScan.setOnClickListener(v -> startActivity(new Intent(this, GnssScanActivity.class)));
        modeScan.setOnClickListener(v -> startActivity(new Intent(this, FlightModeScanActivity.class)));
    }
}
