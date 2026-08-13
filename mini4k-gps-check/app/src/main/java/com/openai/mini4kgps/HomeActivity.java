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
        title.setText("Mini 4K GPS Tool v2.4");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        Button oneShot = new Button(this);
        oneShot.setText("ONE-SHOT FULL GNSS PARAM MAP — READ ONLY");
        LinearLayout.LayoutParams p0 = new LinearLayout.LayoutParams(-1, -2);
        p0.topMargin = pad;
        root.addView(oneShot, p0);

        TextView note = new TextView(this);
        note.setText("v2.4: один большой тест вместо серии проб. Он проходит все доступные FLYC config tables, читает все имена параметров, строит карту GNSS/RF-кандидатов с table/index/current/range/default/hash и сохраняет полный индекс в отчёт. После завершения нажми COPY FULL REPORT. Записи/exec не отправляются.");
        note.setTextSize(14);
        LinearLayout.LayoutParams pn = new LinearLayout.LayoutParams(-1, -2);
        pn.topMargin = pad;
        pn.bottomMargin = pad;
        root.addView(note, pn);

        TextView manual = new TextView(this);
        manual.setText("MANUAL CONTROLS — не запускать для диагностики");
        manual.setTextSize(15);
        root.addView(manual, new LinearLayout.LayoutParams(-1, -2));

        Button atti = new Button(this);
        atti.setText("ATTI ON SPORT / RESTORE");
        root.addView(atti, top(8));

        Button control = new Button(this);
        control.setText("GPS ON / OFF CONTROL");
        root.addView(control, top(8));

        setContentView(scroll);

        oneShot.setOnClickListener(v -> startActivity(new Intent(this, GnssReceiverConfigScanActivity.class)));
        atti.setOnClickListener(v -> startActivity(new Intent(this, AttiControlActivity.class)));
        control.setOnClickListener(v -> startActivity(new Intent(this, FinalActivity.class)));
    }

    private LinearLayout.LayoutParams top(int dp) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = Math.round(dp * getResources().getDisplayMetrics().density);
        return p;
    }
}
