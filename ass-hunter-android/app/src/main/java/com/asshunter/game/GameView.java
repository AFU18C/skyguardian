package com.asshunter.game;

import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.Rect;
import android.graphics.RectF;
import android.graphics.Shader;
import android.graphics.Typeface;
import android.media.AudioAttributes;
import android.media.SoundPool;
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

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.FILTER_BITMAP_FLAG);
    private final Paint shadowPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random random = new Random();
    private final List<Bullet> bullets = new ArrayList<>();
    private final List<Enemy> enemies = new ArrayList<>();
    private final SharedPreferences prefs;

    private final Bitmap portrait;
    private final Bitmap menuArt;
    private final Bitmap playerSheet;
    private final Bitmap enemySheet;
    private final Bitmap tileSheet;
    private final Bitmap environmentSheet;
    private final Bitmap startButtonArt;

    private final Rect playerIdle = new Rect(0, 0, 206, 116);
    private final Rect playerIdle2 = new Rect(206, 0, 412, 116);
    private final Rect playerShot = new Rect(618, 812, 824, 928);
    private final Rect enemyWalk1 = new Rect(420, 339, 560, 452);
    private final Rect enemyWalk2 = new Rect(560, 339, 700, 452);
    private final Rect tileCenter = new Rect(323, 219, 647, 438);
    private final Rect envLeaves = new Rect(1, 1, 168, 159);
    private final Rect envBush = new Rect(170, 1, 283, 113);
    private final Rect envRock = new Rect(285, 31, 332, 77);
    private final Rect envGrass = new Rect(170, 115, 231, 167);

    private final RectF startButton = new RectF();
    private final RectF reloadButton = new RectF();

    private final SoundPool sounds;
    private final int sndShot;
    private final int sndReload;
    private final int sndHit;
    private final int sndCatch;

    private long lastFrame = SystemClock.elapsedRealtime();
    private int mode = MODE_MENU;
    private int score = 0;
    private int record;
    private int lives = 3;
    private int ammo = 2;

    private float playerX;
    private float playerY;
    private float aimRadians = (float) Math.PI;
    private float playerRotation = 0f;
    private float spawnTimer = 0.55f;
    private float shotCooldown = 0f;
    private float walkClock = 0f;

    private boolean caught = false;
    private float caughtTimer = 0f;
    private Enemy catcher;

    private int movePointerId = -1;
    private float stickBaseX;
    private float stickBaseY;
    private float stickX;
    private float stickY;
    private float moveX;
    private float moveY;

    private final float[] decoX = {0.06f, 0.16f, 0.30f, 0.52f, 0.70f, 0.86f, 0.95f};
    private final float[] decoY = {0.12f, 0.78f, 0.24f, 0.84f, 0.15f, 0.72f, 0.35f};

    private static final float PLAYER_SPEED = 315f;
    private static final float BULLET_SPEED = 1050f;
    private static final float ENEMY_SPEED_MIN = 92f;
    private static final float ENEMY_SPEED_MAX = 138f;
    private static final float CATCH_DURATION = 1.25f;

    private static class Bullet {
        float x, y, vx, vy;
        Bullet(float x, float y, float vx, float vy) {
            this.x = x; this.y = y; this.vx = vx; this.vy = vy;
        }
    }

    private static class Enemy {
        float x, y, speed, phase;
        Enemy(float x, float y, float speed, float phase) {
            this.x = x; this.y = y; this.speed = speed; this.phase = phase;
        }
    }

    public GameView(Context context) {
        super(context);
        setFocusable(true);
        paint.setTypeface(Typeface.create("sans-serif-condensed", Typeface.BOLD));
        shadowPaint.setTypeface(paint.getTypeface());

        portrait = decodePortrait(EmbeddedImages.portraitBase64());
        menuArt = BitmapFactory.decodeResource(getResources(), R.drawable.main_menu);
        playerSheet = BitmapFactory.decodeResource(getResources(), R.drawable.player);
        enemySheet = BitmapFactory.decodeResource(getResources(), R.drawable.enemy);
        tileSheet = BitmapFactory.decodeResource(getResources(), R.drawable.tiles);
        environmentSheet = BitmapFactory.decodeResource(getResources(), R.drawable.environment);
        startButtonArt = BitmapFactory.decodeResource(getResources(), R.drawable.start_button);

        prefs = context.getSharedPreferences("ass_hunter_hd", Context.MODE_PRIVATE);
        record = prefs.getInt("record", 0);

        AudioAttributes attrs = new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_GAME)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build();
        sounds = new SoundPool.Builder().setMaxStreams(5).setAudioAttributes(attrs).build();
        sndShot = sounds.load(context, R.raw.shot, 1);
        sndReload = sounds.load(context, R.raw.reload, 1);
        sndHit = sounds.load(context, R.raw.hit, 1);
        sndCatch = sounds.load(context, R.raw.catch_sound, 1);
    }

    private Bitmap decodePortrait(String base64) {
        try {
            byte[] data = Base64.decode(base64, Base64.DEFAULT);
            return BitmapFactory.decodeByteArray(data, 0, data.length);
        } catch (Exception ignored) {
            return null;
        }
    }

    @Override
    protected void onSizeChanged(int w, int h, int oldw, int oldh) {
        playerX = w * 0.50f;
        playerY = h * 0.52f;
        float bw = Math.min(w * 0.25f, 360f);
        float bh = Math.min(h * 0.15f, 105f);
        startButton.set(w * 0.73f - bw / 2f, h * 0.72f, w * 0.73f + bw / 2f, h * 0.72f + bh);
        float rr = Math.min(w, h) * 0.065f;
        reloadButton.set(w - rr * 2.35f, h - rr * 2.20f, w - rr * 0.35f, h - rr * 0.20f);
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        long now = SystemClock.elapsedRealtime();
        float dt = Math.min(0.033f, Math.max(0f, (now - lastFrame) / 1000f));
        lastFrame = now;

        if (mode == MODE_MENU) {
            drawMenu(canvas);
        } else {
            if (mode == MODE_GAME) updateGame(dt);
            drawGame(canvas);
            if (mode == MODE_GAME_OVER) drawGameOver(canvas);
        }
        postInvalidateOnAnimation();
    }

    private void drawMenu(Canvas canvas) {
        int w = getWidth();
        int h = getHeight();
        paint.setShader(new LinearGradient(0, 0, 0, h, Color.rgb(229, 235, 214), Color.rgb(159, 190, 126), Shader.TileMode.CLAMP));
        canvas.drawRect(0, 0, w, h, paint);
        paint.setShader(null);

        if (menuArt != null) {
            Rect src = new Rect(0, 145, menuArt.getWidth(), menuArt.getHeight());
            canvas.drawBitmap(menuArt, src, new Rect(0, 0, w, h), paint);
        }

        // Replace only the hunter's face; keep the original illustrated body, hat and shotgun.
        drawPortraitOval(canvas, w * 0.383f, h * 0.655f, w * 0.425f, h * 0.775f, 0f, true);

        drawOutlinedText(canvas, "RECORD: " + record, w * 0.74f, h * 0.60f, Math.min(w, h) * 0.060f, Color.rgb(255, 218, 42), Paint.Align.CENTER);

        if (startButtonArt != null) {
            canvas.drawBitmap(startButtonArt, null, startButton, paint);
        } else {
            paint.setColor(Color.argb(235, 255, 214, 44));
            canvas.drawRoundRect(startButton, 22f, 22f, paint);
        }
        drawOutlinedText(canvas, "START GAME", startButton.centerX(), startButton.centerY() + startButton.height() * 0.16f,
                Math.min(w, h) * 0.070f, Color.WHITE, Paint.Align.CENTER);

        paint.setColor(Color.argb(175, 25, 46, 21));
        RectF hint = new RectF(w * 0.70f, h * 0.89f, w * 0.96f, h * 0.965f);
        canvas.drawRoundRect(hint, 18f, 18f, paint);
        paint.setColor(Color.WHITE);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setTextSize(Math.min(w, h) * 0.027f);
        canvas.drawText("LEFT THUMB: MOVE   •   RIGHT SIDE: AIM / FIRE", hint.centerX(), hint.centerY() + paint.getTextSize() * 0.35f, paint);
    }

    private void startGame() {
        mode = MODE_GAME;
        score = 0;
        lives = 3;
        ammo = 2;
        bullets.clear();
        enemies.clear();
        caught = false;
        catcher = null;
        caughtTimer = 0f;
        playerX = getWidth() * 0.50f;
        playerY = getHeight() * 0.52f;
        aimRadians = (float) Math.PI;
        playerRotation = 0f;
        spawnTimer = 0.55f;
        movePointerId = -1;
        moveX = moveY = 0f;
        lastFrame = SystemClock.elapsedRealtime();
    }

    private void updateGame(float dt) {
        if (caught) {
            caughtTimer += dt;
            if (caughtTimer >= CATCH_DURATION) {
                caught = false;
                caughtTimer = 0f;
                catcher = null;
                enemies.clear();
                bullets.clear();
                lives--;
                spawnTimer = 0.8f;
                if (lives <= 0) mode = MODE_GAME_OVER;
            }
            return;
        }

        shotCooldown = Math.max(0f, shotCooldown - dt);
        walkClock += dt;

        playerX += moveX * PLAYER_SPEED * dt;
        playerY += moveY * PLAYER_SPEED * dt;
        float mx = getWidth() * 0.08f;
        float my = getHeight() * 0.12f;
        playerX = clamp(playerX, mx, getWidth() - mx);
        playerY = clamp(playerY, my, getHeight() - my);

        if (Math.abs(moveX) + Math.abs(moveY) > 0.12f) {
            aimRadians = (float) Math.atan2(moveY, moveX);
            playerRotation = (float) Math.toDegrees(aimRadians) - 180f;
        }

        spawnTimer -= dt;
        if (spawnTimer <= 0f && enemies.size() < 5) {
            spawnEnemy();
            spawnTimer = Math.max(0.52f, 1.08f - score / 8000f) + random.nextFloat() * 0.45f;
        }

        Iterator<Bullet> bit = bullets.iterator();
        while (bit.hasNext()) {
            Bullet b = bit.next();
            b.x += b.vx * dt;
            b.y += b.vy * dt;
            if (b.x < -60 || b.x > getWidth() + 60 || b.y < -60 || b.y > getHeight() + 60) bit.remove();
        }

        Iterator<Enemy> eit = enemies.iterator();
        while (eit.hasNext()) {
            Enemy e = eit.next();
            float dx = playerX - e.x;
            float dy = playerY - e.y;
            float d = (float) Math.sqrt(dx * dx + dy * dy);
            if (d < 58f) {
                catcher = e;
                caught = true;
                caughtTimer = 0f;
                moveX = moveY = 0f;
                sounds.play(sndCatch, 0.85f, 0.85f, 1, 0, 1f);
                break;
            }
            if (d > 0.001f) {
                e.x += dx / d * e.speed * dt;
                e.y += dy / d * e.speed * dt;
            }
            e.phase += dt * 8f;
        }

        if (!caught) {
            outer:
            for (int bi = bullets.size() - 1; bi >= 0; bi--) {
                Bullet b = bullets.get(bi);
                for (int ei = enemies.size() - 1; ei >= 0; ei--) {
                    Enemy e = enemies.get(ei);
                    float dx = b.x - e.x;
                    float dy = b.y - e.y;
                    if (dx * dx + dy * dy < 34f * 34f) {
                        bullets.remove(bi);
                        enemies.remove(ei);
                        score += 100;
                        if (score > record) {
                            record = score;
                            prefs.edit().putInt("record", record).apply();
                        }
                        sounds.play(sndHit, 0.75f, 0.75f, 1, 0, 1f);
                        continue outer;
                    }
                }
            }
        }
    }

    private void spawnEnemy() {
        int side = random.nextInt(4);
        float x, y;
        float pad = 70f;
        if (side == 0) { x = -pad; y = getHeight() * (0.15f + random.nextFloat() * 0.70f); }
        else if (side == 1) { x = getWidth() + pad; y = getHeight() * (0.15f + random.nextFloat() * 0.70f); }
        else if (side == 2) { x = getWidth() * (0.12f + random.nextFloat() * 0.76f); y = -pad; }
        else { x = getWidth() * (0.12f + random.nextFloat() * 0.76f); y = getHeight() + pad; }
        enemies.add(new Enemy(x, y, ENEMY_SPEED_MIN + random.nextFloat() * (ENEMY_SPEED_MAX - ENEMY_SPEED_MIN), random.nextFloat() * 6.28f));
    }

    private void fireAt(float tx, float ty) {
        if (mode != MODE_GAME || caught || ammo <= 0 || shotCooldown > 0f) return;
        float dx = tx - playerX;
        float dy = ty - playerY;
        float d = (float) Math.sqrt(dx * dx + dy * dy);
        if (d < 1f) return;
        aimRadians = (float) Math.atan2(dy, dx);
        playerRotation = (float) Math.toDegrees(aimRadians) - 180f;
        float ux = dx / d;
        float uy = dy / d;
        bullets.add(new Bullet(playerX + ux * 55f, playerY + uy * 55f, ux * BULLET_SPEED, uy * BULLET_SPEED));
        ammo--;
        shotCooldown = 0.18f;
        sounds.play(sndShot, 1f, 1f, 2, 0, 1f);
    }

    private void reload() {
        if (mode == MODE_GAME && !caught && ammo < 2) {
            ammo = 2;
            sounds.play(sndReload, 0.9f, 0.9f, 1, 0, 1f);
        }
    }

    private void drawGame(Canvas canvas) {
        drawMap(canvas);

        for (Enemy e : enemies) drawEnemy(canvas, e);
        for (Bullet b : bullets) drawBullet(canvas, b);
        drawPlayer(canvas);

        if (caught) drawCaught(canvas);
        drawHud(canvas);
        drawTouchUi(canvas);
    }

    private void drawMap(Canvas canvas) {
        int w = getWidth();
        int h = getHeight();
        paint.setColor(Color.rgb(151, 184, 72));
        canvas.drawRect(0, 0, w, h, paint);

        if (tileSheet != null) {
            canvas.drawBitmap(tileSheet, tileCenter, new Rect(0, 0, w, h), paint);
        }

        if (environmentSheet != null) {
            for (int i = 0; i < decoX.length; i++) {
                Rect src = (i % 3 == 0) ? envLeaves : (i % 3 == 1 ? envBush : envGrass);
                float size = Math.min(w, h) * (i % 3 == 0 ? 0.28f : 0.18f);
                float x = decoX[i] * w;
                float y = decoY[i] * h;
                canvas.drawBitmap(environmentSheet, src, new RectF(x - size / 2, y - size / 2, x + size / 2, y + size / 2), paint);
            }
            float rock = Math.min(w, h) * 0.09f;
            canvas.drawBitmap(environmentSheet, envRock, new RectF(w * 0.36f - rock, h * 0.25f - rock, w * 0.36f + rock, h * 0.25f + rock), paint);
            canvas.drawBitmap(environmentSheet, envRock, new RectF(w * 0.74f - rock * .7f, h * 0.71f - rock * .7f, w * 0.74f + rock * .7f, h * 0.71f + rock * .7f), paint);
        }
    }

    private void drawPlayer(Canvas canvas) {
        float pw = Math.min(getWidth(), getHeight()) * 0.25f;
        float ph = pw * 116f / 206f;
        Rect src = ((int) (walkClock * 7f) & 1) == 0 ? playerIdle : playerIdle2;
        canvas.save();
        canvas.rotate(playerRotation, playerX, playerY);
        if (playerSheet != null) {
            canvas.drawBitmap(playerSheet, src, new RectF(playerX - pw * 0.50f, playerY - ph * 0.50f, playerX + pw * 0.50f, playerY + ph * 0.50f), paint);
        }
        canvas.restore();
    }

    private void drawEnemy(Canvas canvas, Enemy e) {
        float ew = Math.min(getWidth(), getHeight()) * 0.125f;
        float eh = ew * 113f / 140f;
        Rect src = ((int) (e.phase) & 1) == 0 ? enemyWalk1 : enemyWalk2;
        float angle = (float) Math.toDegrees(Math.atan2(playerY - e.y, playerX - e.x)) + 90f;
        canvas.save();
        canvas.rotate(angle, e.x, e.y);
        if (enemySheet != null) {
            canvas.drawBitmap(enemySheet, src, new RectF(e.x - ew / 2, e.y - eh / 2, e.x + ew / 2, e.y + eh / 2), paint);
        }
        canvas.restore();
    }

    private void drawBullet(Canvas canvas, Bullet b) {
        float len = 22f;
        float d = (float) Math.sqrt(b.vx * b.vx + b.vy * b.vy);
        float ux = b.vx / d;
        float uy = b.vy / d;
        paint.setStrokeWidth(5f);
        paint.setStrokeCap(Paint.Cap.ROUND);
        paint.setColor(Color.rgb(255, 239, 75));
        canvas.drawLine(b.x - ux * len, b.y - uy * len, b.x + ux * len, b.y + uy * len, paint);
        paint.setColor(Color.WHITE);
        paint.setStrokeWidth(2f);
        canvas.drawLine(b.x - ux * len * .7f, b.y - uy * len * .7f, b.x + ux * len * .7f, b.y + uy * len * .7f, paint);
    }

    private void drawCaught(Canvas canvas) {
        float bounce = (float) Math.abs(Math.sin(caughtTimer * 18f));
        float ew = Math.min(getWidth(), getHeight()) * 0.22f;
        float eh = ew * 113f / 140f;
        float y = playerY - 18f - bounce * 24f;
        if (enemySheet != null) {
            canvas.drawBitmap(enemySheet, enemyWalk1, new RectF(playerX - ew / 2, y - eh / 2, playerX + ew / 2, y + eh / 2), paint);
        }
        paint.setColor(Color.argb(190, 255, 225, 37));
        paint.setStyle(Paint.Style.STROKE);
        paint.setStrokeWidth(7f);
        float r = Math.min(getWidth(), getHeight()) * (0.10f + bounce * 0.02f);
        canvas.drawCircle(playerX, playerY, r, paint);
        paint.setStyle(Paint.Style.FILL);
        drawOutlinedText(canvas, "CAUGHT!", playerX, playerY - r - 18f, Math.min(getWidth(), getHeight()) * 0.065f, Color.YELLOW, Paint.Align.CENTER);
    }

    private void drawHud(Canvas canvas) {
        int w = getWidth();
        int h = getHeight();
        float unit = Math.min(w, h);

        // Lives: compact round portrait icons, like the original corner HUD.
        for (int i = 0; i < 3; i++) {
            float r = unit * 0.042f;
            float cx = unit * 0.065f + i * r * 2.35f;
            float cy = unit * 0.065f;
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(i < lives ? Color.rgb(224, 54, 48) : Color.argb(80, 255, 255, 255));
            canvas.drawCircle(cx, cy, r + 5f, paint);
            drawPortraitCircle(canvas, cx, cy, r);
        }

        drawOutlinedText(canvas, String.format("%05d", score), w - unit * 0.055f, unit * 0.075f, unit * 0.070f, Color.WHITE, Paint.Align.RIGHT);

        drawOutlinedText(canvas, "REMAINING", w - unit * 0.22f, h - unit * 0.105f, unit * 0.036f, Color.WHITE, Paint.Align.RIGHT);
        for (int i = 0; i < 2; i++) {
            float x = w - unit * (0.18f - i * 0.055f);
            float y = h - unit * 0.065f;
            paint.setColor(i < ammo ? Color.rgb(222, 34, 34) : Color.argb(80, 255, 255, 255));
            canvas.drawRoundRect(new RectF(x - unit * .012f, y - unit * .036f, x + unit * .012f, y + unit * .036f), unit * .008f, unit * .008f, paint);
            paint.setColor(Color.rgb(248, 211, 65));
            canvas.drawRect(x - unit * .012f, y + unit * .017f, x + unit * .012f, y + unit * .036f, paint);
        }
    }

    private void drawTouchUi(Canvas canvas) {
        float unit = Math.min(getWidth(), getHeight());
        if (movePointerId != -1) {
            float radius = unit * 0.105f;
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(unit * .008f);
            paint.setColor(Color.argb(105, 255, 255, 255));
            canvas.drawCircle(stickBaseX, stickBaseY, radius, paint);
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(Color.argb(105, 255, 255, 255));
            canvas.drawCircle(stickX, stickY, radius * .38f, paint);
        }

        paint.setColor(Color.argb(95, 20, 20, 20));
        canvas.drawOval(reloadButton, paint);
        drawOutlinedText(canvas, "R", reloadButton.centerX(), reloadButton.centerY() + reloadButton.height() * .16f,
                unit * 0.055f, Color.WHITE, Paint.Align.CENTER);

        paint.setColor(Color.argb(80, 0, 0, 0));
        paint.setTextAlign(Paint.Align.RIGHT);
        paint.setTextSize(unit * .024f);
        canvas.drawText("TAP RIGHT SIDE TO FIRE", getWidth() - unit * .035f, getHeight() - unit * .17f, paint);
    }

    private void drawGameOver(Canvas canvas) {
        paint.setColor(Color.argb(205, 0, 0, 0));
        canvas.drawRect(0, 0, getWidth(), getHeight(), paint);
        float unit = Math.min(getWidth(), getHeight());
        drawOutlinedText(canvas, "GAME OVER", getWidth() * .5f, getHeight() * .42f, unit * .13f, Color.rgb(245, 68, 47), Paint.Align.CENTER);
        drawOutlinedText(canvas, "SCORE  " + score + "     RECORD  " + record, getWidth() * .5f, getHeight() * .55f, unit * .052f, Color.WHITE, Paint.Align.CENTER);
        drawOutlinedText(canvas, "TAP TO PLAY AGAIN", getWidth() * .5f, getHeight() * .69f, unit * .050f, Color.rgb(255, 224, 72), Paint.Align.CENTER);
    }

    private void drawPortraitCircle(Canvas canvas, float cx, float cy, float r) {
        if (portrait == null) {
            paint.setColor(Color.rgb(232, 185, 145));
            canvas.drawCircle(cx, cy, r, paint);
            return;
        }
        canvas.save();
        Path p = new Path();
        p.addCircle(cx, cy, r, Path.Direction.CW);
        canvas.clipPath(p);
        Rect src = portraitFaceRect();
        canvas.drawBitmap(portrait, src, new RectF(cx - r, cy - r, cx + r, cy + r), paint);
        canvas.restore();
    }

    private void drawPortraitOval(Canvas canvas, float l, float t, float r, float b, float rotation, boolean border) {
        if (portrait == null) return;
        float cx = (l + r) * .5f;
        float cy = (t + b) * .5f;
        canvas.save();
        canvas.rotate(rotation, cx, cy);
        Path p = new Path();
        RectF dest = new RectF(l, t, r, b);
        p.addOval(dest, Path.Direction.CW);
        canvas.clipPath(p);
        canvas.drawBitmap(portrait, portraitFaceRect(), dest, paint);
        canvas.restore();
        if (border) {
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(Math.max(3f, (r - l) * .055f));
            paint.setColor(Color.rgb(45, 35, 22));
            canvas.drawOval(new RectF(l, t, r, b), paint);
            paint.setStyle(Paint.Style.FILL);
        }
    }

    private Rect portraitFaceRect() {
        if (portrait == null) return new Rect();
        int w = portrait.getWidth();
        int h = portrait.getHeight();
        return new Rect((int) (w * .24f), (int) (h * .03f), (int) (w * .76f), (int) (h * .62f));
    }

    private void drawOutlinedText(Canvas canvas, String text, float x, float y, float size, int color, Paint.Align align) {
        shadowPaint.setTextSize(size);
        shadowPaint.setTextAlign(align);
        shadowPaint.setTypeface(paint.getTypeface());
        shadowPaint.setStyle(Paint.Style.STROKE);
        shadowPaint.setStrokeWidth(Math.max(3f, size * .10f));
        shadowPaint.setColor(Color.argb(190, 20, 20, 20));
        canvas.drawText(text, x, y, shadowPaint);
        shadowPaint.setStyle(Paint.Style.FILL);
        shadowPaint.setColor(color);
        canvas.drawText(text, x, y, shadowPaint);
    }

    private float clamp(float v, float lo, float hi) {
        return Math.max(lo, Math.min(hi, v));
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        int action = event.getActionMasked();
        int index = event.getActionIndex();

        if (mode == MODE_MENU) {
            if (action == MotionEvent.ACTION_UP && startButton.contains(event.getX(), event.getY())) {
                startGame();
            }
            return true;
        }

        if (mode == MODE_GAME_OVER) {
            if (action == MotionEvent.ACTION_UP) startGame();
            return true;
        }

        if (action == MotionEvent.ACTION_DOWN || action == MotionEvent.ACTION_POINTER_DOWN) {
            float x = event.getX(index);
            float y = event.getY(index);
            int id = event.getPointerId(index);

            if (reloadButton.contains(x, y)) {
                reload();
                return true;
            }

            if (x < getWidth() * .46f && y > getHeight() * .35f && movePointerId == -1) {
                movePointerId = id;
                stickBaseX = stickX = x;
                stickBaseY = stickY = y;
                moveX = moveY = 0f;
            } else {
                fireAt(x, y);
            }
            return true;
        }

        if (action == MotionEvent.ACTION_MOVE && movePointerId != -1) {
            int pIndex = event.findPointerIndex(movePointerId);
            if (pIndex >= 0) updateStick(event.getX(pIndex), event.getY(pIndex));
            return true;
        }

        if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_POINTER_UP || action == MotionEvent.ACTION_CANCEL) {
            int id = event.getPointerId(index);
            if (id == movePointerId || action == MotionEvent.ACTION_CANCEL) {
                movePointerId = -1;
                moveX = moveY = 0f;
            }
            return true;
        }
        return true;
    }

    private void updateStick(float x, float y) {
        float radius = Math.min(getWidth(), getHeight()) * .105f;
        float dx = x - stickBaseX;
        float dy = y - stickBaseY;
        float d = (float) Math.sqrt(dx * dx + dy * dy);
        if (d > radius && d > 0f) {
            dx = dx / d * radius;
            dy = dy / d * radius;
        }
        stickX = stickBaseX + dx;
        stickY = stickBaseY + dy;
        moveX = dx / radius;
        moveY = dy / radius;
    }
}
