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
        title.setText("Mini 4K GPS Tool v1.1");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        Button control = new Button(this);
        control.setText("GPS ON / OFF CONTROL");
        LinearLayout.LayoutParams p1 = new LinearLayout.LayoutParams(-1, -2);
        p1.topMargin = pad;
        root.addView(control, p1);

        Button scan = new Button(this);
        scan.setText("GNSS SCAN — READ ONLY");
        LinearLayout.LayoutParams p2 = new LinearLayout.LayoutParams(-1, -2);
        p2.topMargin = Math.round(8 * getResources().getDisplayMetrics().density);
        root.addView(scan, p2);

        TextView note = new TextView(this);
        note.setText("GNSS SCAN ничего не записывает в параметры полётного контроллера. Для подключения полностью закройте DJI Fly и используйте верхний телефонный порт RC-N1.");
        note.setTextSize(14);
        LinearLayout.LayoutParams pn = new LinearLayout.LayoutParams(-1, -2);
        pn.topMargin = pad;
        root.addView(note, pn);

        setContentView(root);

        control.setOnClickListener(v -> startActivity(new Intent(this, FinalActivity.class)));
        scan.setOnClickListener(v -> startActivity(new Intent(this, GnssScanActivity.class)));
    }
}
