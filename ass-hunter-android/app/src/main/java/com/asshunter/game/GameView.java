package com.asshunter.game;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RectF;
import android.graphics.Shader;
import android.os.SystemClock;
import android.util.Base64;
import android.view.MotionEvent;
import android.view.View;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.Random;

public class GameView extends View {
    private static final int MODE_MENU = 0;
    private static final int MODE_GAME = 1;
    private static final int MODE_GAME_OVER = 2;

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random random = new Random();
    private final List<Bullet> bullets = new ArrayList<>();
    private final List<Target> targets = new ArrayList<>();
    private final Bitmap portrait;

    private final RectF startButton = new RectF();
    private final RectF leftButton = new RectF();
    private final RectF rightButton = new RectF();
    private final RectF reloadButton = new RectF();
    private final RectF fireButton = new RectF();

    private long lastFrame = SystemClock.elapsedRealtime();
    private float spawnTimer = 0f;
    private float playerX = 0f;
    private int score = 0;
    private int record = 0;
    private int lives = 3;
    private int ammo = 2;
    private float timeLeft = 60f;
    private float shotCooldown = 0f;
    private int mode = MODE_MENU;
    private boolean leftPressed;
    private boolean rightPressed;

    private boolean caught;
    private float caughtTimer;
    private float caughtPhase;

    private static final float PLAYER_SPEED = 520f;
    private static final float BULLET_SPEED = 1250f;
    private static final float CATCH_DURATION = 1.35f;

    public GameView(Context context) {
        super(context);
        paint.setTypeface(android.graphics.Typeface.create("sans-serif-condensed", android.graphics.Typeface.BOLD));
        portrait = decode(EmbeddedImages.portraitBase64());
        setFocusable(true);
    }

    private Bitmap decode(String base64) {
        try {
            byte[] data = Base64.decode(base64, Base64.DEFAULT);
            return BitmapFactory.decodeByteArray(data, 0, data.length);
        } catch (Exception e) {
            return null;
        }
    }

    private static class Bullet {
        float x, y;
        Bullet(float x, float y) { this.x = x; this.y = y; }
    }

    private static class Target {
        float x, y, vy, phase, drift;
        Target(float x, float y, float vy, float phase, float drift) {
            this.x = x; this.y = y; this.vy = vy; this.phase = phase; this.drift = drift;
        }
    }

    @Override
    protected void onSizeChanged(int w, int h, int oldw, int oldh) {
        playerX = w * 0.5f;
        startButton.set(w * 0.22f, h * 0.82f, w * 0.78f, h * 0.92f);
        leftButton.set(w * 0.03f, h * 0.84f, w * 0.22f, h * 0.97f);
        rightButton.set(w * 0.25f, h * 0.84f, w * 0.44f, h * 0.97f);
        reloadButton.set(w * 0.49f, h * 0.84f, w * 0.72f, h * 0.97f);
        fireButton.set(w * 0.77f, h * 0.78f, w * 0.97f, h * 0.97f);
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        long now = SystemClock.elapsedRealtime();
        float dt = Math.min(0.033f, (now - lastFrame) / 1000f);
        lastFrame = now;

        if (mode == MODE_MENU) {
            drawMenu(canvas);
        } else {
            if (mode == MODE_GAME) update(dt);
            drawGame(canvas);
            if (mode == MODE_GAME_OVER) drawGameOver(canvas);
        }
        postInvalidateOnAnimation();
    }

    private void update(float dt) {
        if (caught) {
            caughtTimer += dt;
            caughtPhase += dt * 18f;
            if (caughtTimer >= CATCH_DURATION) {
                caught = false;
                caughtTimer = 0f;
                caughtPhase = 0f;
                lives--;
                bullets.clear();
                targets.clear();
                spawnTimer = 0.45f;
                if (lives <= 0) mode = MODE_GAME_OVER;
            }
            return;
        }

        if (leftPressed) playerX -= PLAYER_SPEED * dt;
        if (rightPressed) playerX += PLAYER_SPEED * dt;
        float margin = getWidth() * 0.10f;
        playerX = Math.max(margin, Math.min(getWidth() - margin, playerX));

        timeLeft -= dt;
        shotCooldown = Math.max(0f, shotCooldown - dt);
        if (timeLeft <= 0f) {
            timeLeft = 0f;
            mode = MODE_GAME_OVER;
            return;
        }

        spawnTimer -= dt;
        if (spawnTimer <= 0f) {
            spawnTimer = 0.70f + random.nextFloat() * 0.55f;
            float x = getWidth() * (0.12f + random.nextFloat() * 0.76f);
            float vy = 220f + random.nextFloat() * 150f;
            float phase = random.nextFloat() * 6.28f;
            float drift = 25f + random.nextFloat() * 45f;
            targets.add(new Target(x, getHeight() * 0.16f, vy, phase, drift));
        }

        Iterator<Bullet> bulletIt = bullets.iterator();
        while (bulletIt.hasNext()) {
            Bullet b = bulletIt.next();
            b.y -= BULLET_SPEED * dt;
            if (b.y < -40f) bulletIt.remove();
        }

        Iterator<Target> targetIt = targets.iterator();
        while (targetIt.hasNext()) {
            Target t = targetIt.next();
            t.phase += dt * 4.2f;
            t.x += (float) Math.sin(t.phase) * t.drift * dt;
            t.y += t.vy * dt;
            if (t.y >= getHeight() * 0.67f) {
                targetIt.remove();
                startCatch();
                break;
            }
        }

        if (!caught) {
            outer:
            for (int bi = bullets.size() - 1; bi >= 0; bi--) {
                Bullet b = bullets.get(bi);
                for (int ti = targets.size() - 1; ti >= 0; ti--) {
                    Target t = targets.get(ti);
                    RectF hit = targetRect(t);
                    if (hit.contains(b.x, b.y)) {
                        bullets.remove(bi);
                        targets.remove(ti);
                        score += 10;
                        if (score > record) record = score;
                        continue outer;
                    }
                }
            }
        }
    }

    private void startCatch() {
        caught = true;
        caughtTimer = 0f;
        caughtPhase = 0f;
        leftPressed = false;
        rightPressed = false;
    }

    private RectF targetRect(Target t) {
        float w = getWidth() * 0.13f;
        float h = getHeight() * 0.10f;
        return new RectF(t.x - w / 2f, t.y - h / 2f, t.x + w / 2f, t.y + h / 2f);
    }

    private void drawMenu(Canvas canvas) {
        drawBackground(canvas);

        drawWoodPanel(canvas, getWidth() * 0.07f, getHeight() * 0.04f, getWidth() * 0.86f, getHeight() * 0.18f);
        paint.setFakeBoldText(true);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setColor(Color.rgb(255, 198, 36));
        paint.setTextSize(getWidth() * 0.14f);
        canvas.drawText("ASS", getWidth() * 0.50f, getHeight() * 0.13f, paint);
        paint.setColor(Color.rgb(236, 98, 35));
        paint.setTextSize(getWidth() * 0.115f);
        canvas.drawText("HUNTER", getWidth() * 0.50f, getHeight() * 0.205f, paint);

        RectF photoFrame = new RectF(getWidth() * 0.18f, getHeight() * 0.28f, getWidth() * 0.82f, getHeight() * 0.68f);
        paint.setColor(Color.rgb(95, 62, 31));
        canvas.drawRoundRect(photoFrame, 28f, 28f, paint);
        RectF photo = new RectF(photoFrame.left + 10f, photoFrame.top + 10f, photoFrame.right - 10f, photoFrame.bottom - 10f);
        if (portrait != null) {
            canvas.save();
            Path clip = new Path();
            clip.addRoundRect(photo, 22f, 22f, Path.Direction.CW);
            canvas.clipPath(clip);
            canvas.drawBitmap(portrait, null, photo, paint);
            canvas.restore();
        }

        paint.setColor(Color.WHITE);
        paint.setTextSize(getWidth() * 0.042f);
        canvas.drawText("ANDROID EDITION", getWidth() * 0.50f, getHeight() * 0.74f, paint);
        paint.setColor(Color.rgb(255, 226, 70));
        paint.setTextSize(getWidth() * 0.045f);
        canvas.drawText("RECORD: " + record, getWidth() * 0.50f, getHeight() * 0.785f, paint);

        paint.setShader(new LinearGradient(startButton.left, startButton.top, startButton.right, startButton.bottom,
                Color.rgb(255, 226, 73), Color.rgb(235, 151, 29), Shader.TileMode.CLAMP));
        canvas.drawRoundRect(startButton, 30f, 30f, paint);
        paint.setShader(null);
        paint.setColor(Color.BLACK);
        paint.setTextSize(getWidth() * 0.085f);
        canvas.drawText("START GAME", startButton.centerX(), startButton.centerY() + getWidth() * 0.028f, paint);
        paint.setTextAlign(Paint.Align.LEFT);
    }

    private void drawGame(Canvas canvas) {
        drawBackground(canvas);

        drawWoodPanel(canvas, 14f, 14f, getWidth() * 0.60f, getHeight() * 0.13f);
        paint.setFakeBoldText(true);
        paint.setTextAlign(Paint.Align.LEFT);
        paint.setColor(Color.rgb(255, 223, 74));
        paint.setTextSize(getWidth() * 0.060f);
        canvas.drawText("ASS HUNTER", 30f, getHeight() * 0.062f, paint);
        paint.setColor(Color.WHITE);
        paint.setTextSize(getWidth() * 0.038f);
        paint.setFakeBoldText(false);
        canvas.drawText("SCORE " + score + "   RECORD " + record, 30f, getHeight() * 0.105f, paint);

        drawWoodPanel(canvas, getWidth() * 0.70f, 14f, getWidth() * 0.27f, getHeight() * 0.13f);
        canvas.drawText("LIVES " + lives, getWidth() * 0.73f, getHeight() * 0.060f, paint);
        canvas.drawText("TIME " + Math.max(0, (int) timeLeft), getWidth() * 0.73f, getHeight() * 0.100f, paint);

        for (Target t : targets) drawRunner(canvas, t);
        for (Bullet b : bullets) {
            paint.setColor(Color.YELLOW);
            canvas.drawOval(new RectF(b.x - 7f, b.y - 18f, b.x + 7f, b.y + 18f), paint);
        }

        drawHunter(canvas, playerX, getHeight() * 0.78f, getWidth() * 0.26f, caught);
        if (caught) drawCatchAnimation(canvas);

        drawControl(canvas, leftButton, "LEFT", leftPressed);
        drawControl(canvas, rightButton, "RIGHT", rightPressed);
        drawControl(canvas, reloadButton, ammo == 2 ? "LOADED" : "RELOAD", false);
        drawFire(canvas);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setColor(Color.WHITE);
        paint.setTextSize(getWidth() * 0.037f);
        canvas.drawText("AMMO " + ammo + "/2", reloadButton.centerX(), reloadButton.top - 10f, paint);
        paint.setTextAlign(Paint.Align.LEFT);
    }

    private void drawBackground(Canvas canvas) {
        paint.setShader(new LinearGradient(0, 0, 0, getHeight(), Color.rgb(102, 190, 248), Color.rgb(205, 239, 255), Shader.TileMode.CLAMP));
        canvas.drawRect(0, 0, getWidth(), getHeight(), paint);
        paint.setShader(null);

        paint.setColor(Color.WHITE);
        drawCloud(canvas, getWidth() * 0.18f, getHeight() * 0.13f, getWidth() * 0.08f);
        drawCloud(canvas, getWidth() * 0.78f, getHeight() * 0.18f, getWidth() * 0.065f);

        paint.setColor(Color.rgb(73, 151, 62));
        for (int i = 0; i < 24; i++) {
            float x = i * getWidth() / 23f;
            float top = getHeight() * (0.57f + (i % 4) * 0.018f);
            canvas.drawRect(x, top, x + getWidth() * 0.018f, getHeight(), paint);
        }
        paint.setColor(Color.rgb(54, 125, 50));
        for (int i = 0; i < 14; i++) {
            float x = i * getWidth() / 13f;
            canvas.drawRect(x, getHeight() * 0.66f, x + getWidth() * 0.026f, getHeight(), paint);
        }
    }

    private void drawCloud(Canvas canvas, float x, float y, float r) {
        canvas.drawCircle(x, y, r, paint);
        canvas.drawCircle(x + r * 0.8f, y, r * 0.70f, paint);
        canvas.drawCircle(x - r * 0.65f, y + r * 0.15f, r * 0.58f, paint);
    }

    private void drawWoodPanel(Canvas canvas, float x, float y, float w, float h) {
        paint.setColor(Color.rgb(115, 76, 38));
        canvas.drawRoundRect(new RectF(x, y, x + w, y + h), 18f, 18f, paint);
        paint.setColor(Color.rgb(87, 52, 25));
        canvas.drawRoundRect(new RectF(x + 7f, y + 7f, x + w - 7f, y + h - 7f), 14f, 14f, paint);
    }

    private void drawRunner(Canvas canvas, Target t) {
        RectF r = targetRect(t);
        float cx = r.centerX();
        float cy = r.centerY();
        paint.setColor(Color.rgb(239, 198, 159));
        canvas.drawCircle(cx, cy - r.height() * 0.27f, r.width() * 0.16f, paint);
        paint.setColor(Color.rgb(65, 123, 192));
        canvas.drawRoundRect(new RectF(cx - r.width() * 0.20f, cy - r.height() * 0.12f,
                cx + r.width() * 0.20f, cy + r.height() * 0.20f), 16f, 16f, paint);
        paint.setColor(Color.rgb(105, 72, 43));
        canvas.drawOval(new RectF(cx - r.width() * 0.24f, cy + r.height() * 0.10f,
                cx + r.width() * 0.24f, cy + r.height() * 0.34f), paint);
        paint.setColor(Color.BLACK);
        paint.setStrokeWidth(6f);
        canvas.drawLine(cx - r.width() * 0.10f, cy + r.height() * 0.30f, cx - r.width() * 0.22f, cy + r.height() * 0.48f, paint);
        canvas.drawLine(cx + r.width() * 0.10f, cy + r.height() * 0.30f, cx + r.width() * 0.22f, cy + r.height() * 0.48f, paint);
    }

    private void drawHunter(Canvas canvas, float x, float groundY, float size, boolean flattened) {
        float bodyH = size * 1.35f;
        float squash = flattened ? 0.60f : 1f;
        float y = groundY + (flattened ? size * 0.16f : 0f);

        paint.setColor(Color.rgb(91, 101, 50));
        canvas.drawRoundRect(new RectF(x - size * 0.38f, y - bodyH * squash, x + size * 0.38f, y), 28f, 28f, paint);
        paint.setColor(Color.rgb(80, 50, 24));
        canvas.drawRoundRect(new RectF(x - size * 0.42f, y - bodyH * 0.18f, x + size * 0.42f, y - bodyH * 0.05f), 12f, 12f, paint);

        float headR = size * 0.23f;
        float headY = y - bodyH * squash - headR * 0.82f;
        paint.setColor(Color.rgb(238, 202, 174));
        canvas.drawCircle(x, headY, headR, paint);
        if (portrait != null) {
            RectF face = new RectF(x - headR, headY - headR, x + headR, headY + headR);
            Path clip = new Path();
            clip.addCircle(x, headY, headR, Path.Direction.CW);
            canvas.save();
            canvas.clipPath(clip);
            canvas.drawBitmap(portrait, null, face, paint);
            canvas.restore();
        }

        paint.setColor(Color.rgb(147, 151, 156));
        canvas.drawRoundRect(new RectF(x - headR * 0.95f, headY - headR * 1.05f,
                x + headR * 0.95f, headY - headR * 0.18f), 25f, 25f, paint);
        Path e1 = new Path();
        e1.moveTo(x - headR * 0.65f, headY - headR * 0.78f);
        e1.lineTo(x - headR * 1.00f, headY - headR * 1.75f);
        e1.lineTo(x - headR * 0.25f, headY - headR * 1.10f);
        e1.close();
        canvas.drawPath(e1, paint);
        Path e2 = new Path();
        e2.moveTo(x + headR * 0.65f, headY - headR * 0.78f);
        e2.lineTo(x + headR * 1.00f, headY - headR * 1.75f);
        e2.lineTo(x + headR * 0.25f, headY - headR * 1.10f);
        e2.close();
        canvas.drawPath(e2, paint);

        if (!flattened) {
            paint.setColor(Color.rgb(102, 63, 28));
            canvas.drawRoundRect(new RectF(x - size * 0.55f, y - bodyH * 0.52f, x + size * 0.02f, y - bodyH * 0.34f), 15f, 15f, paint);
            paint.setColor(Color.rgb(175, 178, 180));
            canvas.drawRoundRect(new RectF(x, y - bodyH * 0.50f, x + size * 0.78f, y - bodyH * 0.42f), 10f, 10f, paint);
            canvas.drawRoundRect(new RectF(x, y - bodyH * 0.39f, x + size * 0.78f, y - bodyH * 0.31f), 10f, 10f, paint);
        }
    }

    private void drawCatchAnimation(Canvas canvas) {
        float jump = Math.min(1f, caughtTimer / 0.28f);
        float settle = getHeight() * 0.66f + (1f - jump) * getHeight() * 0.18f;
        float wiggle = (float) Math.sin(caughtPhase) * getWidth() * 0.032f;
        float cx = playerX + wiggle;
        float cy = settle;
        float w = getWidth() * 0.27f;
        float h = getHeight() * 0.14f;

        paint.setColor(Color.rgb(65, 123, 192));
        canvas.drawRoundRect(new RectF(cx - w * 0.28f, cy - h * 0.55f, cx + w * 0.28f, cy - h * 0.12f), 22f, 22f, paint);
        paint.setColor(Color.rgb(110, 75, 45));
        canvas.drawOval(new RectF(cx - w * 0.42f, cy - h * 0.12f, cx + w * 0.42f, cy + h * 0.36f), paint);
        paint.setColor(Color.rgb(239, 198, 159));
        canvas.drawCircle(cx, cy - h * 0.70f, w * 0.14f, paint);

        paint.setTextAlign(Paint.Align.CENTER);
        paint.setFakeBoldText(true);
        paint.setTextSize(getWidth() * 0.065f);
        paint.setColor(Color.rgb(255, 229, 70));
        canvas.drawText("ПОПАЛСЯ!", getWidth() * 0.5f, getHeight() * 0.30f, paint);
        paint.setTextAlign(Paint.Align.LEFT);
    }

    private void drawControl(Canvas canvas, RectF r, String label, boolean pressed) {
        paint.setColor(pressed ? Color.rgb(245, 207, 65) : Color.argb(205, 250, 250, 250));
        canvas.drawRoundRect(r, 25f, 25f, paint);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setColor(Color.BLACK);
        paint.setTextSize(getWidth() * 0.045f);
        paint.setFakeBoldText(true);
        canvas.drawText(label, r.centerX(), r.centerY() + getWidth() * 0.017f, paint);
        paint.setTextAlign(Paint.Align.LEFT);
    }

    private void drawFire(Canvas canvas) {
        paint.setColor(Color.rgb(232, 64, 36));
        canvas.drawRoundRect(fireButton, 36f, 36f, paint);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setColor(Color.WHITE);
        paint.setTextSize(getWidth() * 0.052f);
        paint.setFakeBoldText(true);
        canvas.drawText("FIRE", fireButton.centerX(), fireButton.centerY() + getWidth() * 0.018f, paint);
        paint.setTextAlign(Paint.Align.LEFT);
    }

    private void drawGameOver(Canvas canvas) {
        paint.setColor(Color.argb(205, 0, 0, 0));
        canvas.drawRect(0, 0, getWidth(), getHeight(), paint);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setColor(Color.WHITE);
        paint.setFakeBoldText(true);
        paint.setTextSize(getWidth() * 0.105f);
        canvas.drawText("GAME OVER", getWidth() * 0.5f, getHeight() * 0.38f, paint);
        paint.setTextSize(getWidth() * 0.055f);
        canvas.drawText("SCORE: " + score, getWidth() * 0.5f, getHeight() * 0.47f, paint);
        canvas.drawText("RECORD: " + record, getWidth() * 0.5f, getHeight() * 0.53f, paint);
        drawControl(canvas, startButton, "RESTART", false);
        paint.setTextAlign(Paint.Align.LEFT);
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        float x = event.getX();
        float y = event.getY();

        if (mode == MODE_MENU) {
            if (event.getActionMasked() == MotionEvent.ACTION_DOWN && startButton.contains(x, y)) startGame();
            return true;
        }
        if (mode == MODE_GAME_OVER) {
            if (event.getActionMasked() == MotionEvent.ACTION_DOWN && startButton.contains(x, y)) startGame();
            return true;
        }
        if (caught) return true;

        switch (event.getActionMasked()) {
            case MotionEvent.ACTION_DOWN:
            case MotionEvent.ACTION_POINTER_DOWN:
            case MotionEvent.ACTION_MOVE:
                updatePressed(event);
                if (event.getActionMasked() == MotionEvent.ACTION_DOWN) {
                    if (reloadButton.contains(x, y)) reload();
                    else if (fireButton.contains(x, y)) shoot();
                }
                break;
            case MotionEvent.ACTION_UP:
            case MotionEvent.ACTION_CANCEL:
                leftPressed = false;
                rightPressed = false;
                break;
        }
        return true;
    }

    private void updatePressed(MotionEvent event) {
        leftPressed = false;
        rightPressed = false;
        for (int i = 0; i < event.getPointerCount(); i++) {
            float x = event.getX(i);
            float y = event.getY(i);
            if (leftButton.contains(x, y)) leftPressed = true;
            if (rightButton.contains(x, y)) rightPressed = true;
        }
    }

    private void shoot() {
        if (mode != MODE_GAME || caught || ammo <= 0 || shotCooldown > 0f) return;
        bullets.add(new Bullet(playerX + getWidth() * 0.16f, getHeight() * 0.54f));
        ammo--;
        shotCooldown = 0.18f;
    }

    private void reload() {
        if (mode == MODE_GAME && !caught) ammo = 2;
    }

    private void startGame() {
        bullets.clear();
        targets.clear();
        score = 0;
        lives = 3;
        ammo = 2;
        timeLeft = 60f;
        spawnTimer = 0.45f;
        shotCooldown = 0f;
        caught = false;
        caughtTimer = 0f;
        caughtPhase = 0f;
        playerX = getWidth() * 0.50f;
        mode = MODE_GAME;
        lastFrame = SystemClock.elapsedRealtime();
        invalidate();
    }
}
