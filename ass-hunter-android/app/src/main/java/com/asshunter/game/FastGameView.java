package com.asshunter.game;

import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.*;
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

public class FastGameView extends View {
    private static final int MENU = 0, GAME = 1, GAME_OVER = 2;

    private final Paint p = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.FILTER_BITMAP_FLAG);
    private final Paint text = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random rnd = new Random();
    private final List<Enemy> enemies = new ArrayList<>();
    private final List<Bullet> bullets = new ArrayList<>();
    private final SharedPreferences prefs;

    private final Bitmap hunterMenu;
    private final Bitmap player;
    private final Bitmap enemy;
    private final Bitmap tiles;
    private final Bitmap env;
    private final Bitmap face;

    private final Rect player1 = new Rect(0, 0, 206, 116);
    private final Rect player2 = new Rect(206, 0, 412, 116);
    private final Rect enemy1 = new Rect(420, 339, 560, 452);
    private final Rect enemy2 = new Rect(560, 339, 700, 452);
    private final Rect tile = new Rect(323, 219, 647, 438);
    private final Rect leaves = new Rect(1, 1, 168, 159);
    private final Rect bush = new Rect(170, 1, 283, 113);
    private final Rect rock = new Rect(285, 31, 332, 77);
    private final Rect grass = new Rect(170, 115, 231, 167);

    private final RectF startButton = new RectF();
    private final RectF reloadButton = new RectF();

    private final SoundPool sounds;
    private final int sndShot, sndReload, sndHit, sndCatch;

    private int mode = MENU;
    private int score = 0;
    private int record;
    private int lives = 5;
    private int ammo = 2;

    private float px, py;
    private float rotation = 0f;
    private float spawnTimer = 0.8f;
    private float shotCooldown = 0f;
    private float walkClock = 0f;
    private float caughtTimer = 0f;

    private float crossX, crossY, crossTimer;

    private int movePointer = -1;
    private float baseX, baseY, stickX, stickY, moveX, moveY;

    private long lastFrame = SystemClock.elapsedRealtime();

    private static final float PLAYER_SPEED = 500f;
    private static final float ENEMY_SPEED_MIN = 110f;
    private static final float ENEMY_SPEED_MAX = 160f;
    private static final float BULLET_SPEED = 1800f;
    private static final float CATCH_TIME = 1.0f;

    private static class Enemy {
        float x, y, speed, phase;
        Enemy(float x, float y, float speed, float phase) {
            this.x = x; this.y = y; this.speed = speed; this.phase = phase;
        }
    }

    private static class Bullet {
        float x, y, vx, vy;
        Bullet(float x, float y, float vx, float vy) {
            this.x = x; this.y = y; this.vx = vx; this.vy = vy;
        }
    }

    public FastGameView(Context c) {
        super(c);
        setFocusable(true);
        p.setTypeface(Typeface.DEFAULT_BOLD);
        text.setTypeface(Typeface.DEFAULT_BOLD);

        hunterMenu = decode(EmbeddedHunterV7.data());
        face = decode(EmbeddedImages.portraitBase64());
        player = BitmapFactory.decodeResource(getResources(), R.drawable.player);
        enemy = BitmapFactory.decodeResource(getResources(), R.drawable.enemy);
        tiles = BitmapFactory.decodeResource(getResources(), R.drawable.tiles);
        env = BitmapFactory.decodeResource(getResources(), R.drawable.environment);

        prefs = c.getSharedPreferences("ass_hunter_hd", Context.MODE_PRIVATE);
        record = prefs.getInt("record", 0);

        AudioAttributes a = new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_GAME)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build();
        sounds = new SoundPool.Builder().setMaxStreams(5).setAudioAttributes(a).build();
        sndShot = sounds.load(c, R.raw.shot, 1);
        sndReload = sounds.load(c, R.raw.reload, 1);
        sndHit = sounds.load(c, R.raw.hit, 1);
        sndCatch = sounds.load(c, R.raw.catch_sound, 1);
    }

    private Bitmap decode(String s) {
        try {
            byte[] d = Base64.decode(s, Base64.DEFAULT);
            return BitmapFactory.decodeByteArray(d, 0, d.length);
        } catch (Exception e) {
            return null;
        }
    }

    private void resetPaint() {
        p.setAlpha(255);
        p.setShader(null);
        p.setStyle(Paint.Style.FILL);
        p.setStrokeWidth(1f);
        p.setStrokeCap(Paint.Cap.BUTT);
        p.setColor(Color.WHITE);
    }

    @Override
    protected void onSizeChanged(int w, int h, int oldw, int oldh) {
        px = w * .5f;
        py = h * .52f;
        startButton.set(w * .58f, h * .55f, w * .95f, h * .84f);
        float rr = Math.min(w, h) * .065f;
        reloadButton.set(w - rr * 2.35f, h - rr * 2.20f, w - rr * .35f, h - rr * .20f);
    }

    @Override
    protected void onDraw(Canvas c) {
        long now = SystemClock.elapsedRealtime();
        float dt = Math.min(.033f, Math.max(0f, (now - lastFrame) / 1000f));
        lastFrame = now;
        resetPaint();

        if (mode == MENU) {
            drawMenu(c);
        } else {
            if (mode == GAME) update(dt);
            drawGame(c);
            if (mode == GAME_OVER) drawGameOver(c);
        }
        postInvalidateOnAnimation();
    }

    private void drawMenu(Canvas c) {
        int w = getWidth(), h = getHeight();
        float u = Math.min(w, h);

        resetPaint();
        p.setShader(new LinearGradient(0, 0, 0, h,
                Color.rgb(186, 224, 244), Color.rgb(218, 235, 218), Shader.TileMode.CLAMP));
        c.drawRect(0, 0, w, h, p);
        p.setShader(null);

        resetPaint();
        p.setColor(Color.rgb(67, 112, 80));
        for (int i = -1; i < 18; i++) {
            float cx = i * w / 16f;
            float base = h * .63f;
            float th = h * (.18f + (i % 3) * .025f);
            Path tree = new Path();
            tree.moveTo(cx, base - th);
            tree.lineTo(cx - th * .22f, base);
            tree.lineTo(cx + th * .22f, base);
            tree.close();
            c.drawPath(tree, p);
        }

        p.setColor(Color.rgb(103, 151, 78));
        c.drawRect(0, h * .58f, w, h, p);
        for (int i = 0; i < 110; i++) {
            float x = (i * 79f) % (w + 30f) - 15f;
            float tip = h * (.53f + ((i * 37) % 32) / 100f);
            p.setStrokeWidth(2f + (i % 3));
            p.setColor((i & 1) == 0 ? Color.rgb(45, 108, 50) : Color.rgb(67, 131, 59));
            c.drawLine(x, h, x + ((i % 5) - 2) * 6f, tip, p);
        }

        if (hunterMenu != null) {
            float hh = h * .91f;
            float hw = hh * hunterMenu.getWidth() / hunterMenu.getHeight();
            RectF dst = new RectF(w * .025f, h - hh + h * .02f, w * .025f + hw, h + h * .02f);
            resetPaint();
            c.drawBitmap(hunterMenu, null, dst, p);
        }

        RectF titleBox = new RectF(w * .43f, h * .065f, w * .94f, h * .31f);
        resetPaint();
        p.setColor(Color.rgb(110, 66, 31));
        c.drawRoundRect(titleBox, u * .025f, u * .025f, p);
        p.setStyle(Paint.Style.STROKE);
        p.setStrokeWidth(u * .007f);
        p.setColor(Color.rgb(55, 34, 18));
        c.drawRoundRect(titleBox, u * .025f, u * .025f, p);
        p.setStyle(Paint.Style.FILL);
        outlinedText(c, "ASS HUNTER", titleBox.centerX(), titleBox.centerY() + u * .035f,
                u * .105f, Color.rgb(255, 216, 48), Paint.Align.CENTER);

        outlinedText(c, "WATCH OUT BEHIND YOU HUNTER!",
                w * .70f, h * .39f, u * .045f,
                Color.rgb(231, 59, 44), Paint.Align.CENTER);

        outlinedText(c, "RECORD: " + record,
                w * .80f, h * .49f, u * .050f,
                Color.rgb(255, 218, 42), Paint.Align.CENTER);

        resetPaint();
        p.setColor(Color.rgb(126, 74, 35));
        c.drawRoundRect(startButton, u * .025f, u * .025f, p);
        p.setStyle(Paint.Style.STROKE);
        p.setStrokeWidth(u * .007f);
        p.setColor(Color.rgb(58, 35, 19));
        c.drawRoundRect(startButton, u * .025f, u * .025f, p);
        p.setStyle(Paint.Style.FILL);
        outlinedText(c, "START GAME", startButton.centerX(),
                startButton.centerY() + u * .025f,
                u * .070f, Color.WHITE, Paint.Align.CENTER);
    }

    private void startGame() {
        mode = GAME;
        score = 0;
        lives = 5;
        ammo = 2;
        enemies.clear();
        bullets.clear();
        px = getWidth() * .50f;
        py = getHeight() * .52f;
        rotation = 0f;
        spawnTimer = .75f;
        shotCooldown = 0f;
        walkClock = 0f;
        caughtTimer = 0f;
        crossTimer = 0f;
        movePointer = -1;
        moveX = moveY = 0f;
        lastFrame = SystemClock.elapsedRealtime();
    }

    private void update(float dt) {
        crossTimer = Math.max(0f, crossTimer - dt);

        if (caughtTimer > 0f) {
            caughtTimer += dt;
            if (caughtTimer >= CATCH_TIME) {
                caughtTimer = 0f;
                enemies.clear();
                bullets.clear();
                lives--;
                spawnTimer = .75f;
                if (lives <= 0) mode = GAME_OVER;
            }
            return;
        }

        shotCooldown = Math.max(0f, shotCooldown - dt);
        walkClock += dt;

        px += moveX * PLAYER_SPEED * dt;
        py += moveY * PLAYER_SPEED * dt;
        float ex = getWidth() * .065f;
        float ey = getHeight() * .10f;
        px = clamp(px, ex, getWidth() - ex);
        py = clamp(py, ey, getHeight() - ey);

        spawnTimer -= dt;
        if (spawnTimer <= 0f && enemies.size() < 5) {
            spawnEnemy();
            float difficulty = Math.min(.30f, score / 20000f);
            spawnTimer = Math.max(.52f, .82f - difficulty) + rnd.nextFloat() * .32f;
        }

        Iterator<Bullet> bi = bullets.iterator();
        while (bi.hasNext()) {
            Bullet b = bi.next();
            b.x += b.vx * dt;
            b.y += b.vy * dt;
            if (b.x < -120 || b.y < -120 || b.x > getWidth() + 120 || b.y > getHeight() + 120) {
                bi.remove();
            }
        }

        for (Enemy e : enemies) {
            float dx = px - e.x;
            float dy = py - e.y;
            float d = (float)Math.hypot(dx, dy);
            float catchRadius = Math.min(getWidth(), getHeight()) * .070f;
            if (d < catchRadius) {
                caughtTimer = .001f;
                moveX = moveY = 0f;
                sounds.play(sndCatch, .85f, .85f, 1, 0, 1f);
                break;
            }
            if (d > 1f) {
                e.x += dx / d * e.speed * dt;
                e.y += dy / d * e.speed * dt;
            }
            e.phase += dt * 9.5f;
        }

        if (caughtTimer <= 0f) {
            float hitRadius = Math.min(getWidth(), getHeight()) * .11f;
            outer:
            for (int i = bullets.size() - 1; i >= 0; i--) {
                Bullet b = bullets.get(i);
                for (int j = enemies.size() - 1; j >= 0; j--) {
                    Enemy e = enemies.get(j);
                    float dx = b.x - e.x;
                    float dy = b.y - e.y;
                    if (dx * dx + dy * dy < hitRadius * hitRadius) {
                        bullets.remove(i);
                        enemies.remove(j);
                        score += 100;
                        if (score > record) {
                            record = score;
                            prefs.edit().putInt("record", record).apply();
                        }
                        sounds.play(sndHit, .8f, .8f, 1, 0, 1f);
                        continue outer;
                    }
                }
            }
        }
    }

    private void spawnEnemy() {
        int side = rnd.nextInt(4);
        float x, y;
        float pad = 90f;

        if (side == 0) {
            x = -pad;
            y = getHeight() * (.15f + rnd.nextFloat() * .70f);
        } else if (side == 1) {
            x = getWidth() + pad;
            y = getHeight() * (.15f + rnd.nextFloat() * .70f);
        } else if (side == 2) {
            x = getWidth() * (.10f + rnd.nextFloat() * .80f);
            y = -pad;
        } else {
            x = getWidth() * (.10f + rnd.nextFloat() * .80f);
            y = getHeight() + pad;
        }

        float speed = ENEMY_SPEED_MIN + rnd.nextFloat() * (ENEMY_SPEED_MAX - ENEMY_SPEED_MIN);
        speed += Math.min(55f, score / 80f);
        enemies.add(new Enemy(x, y, speed, rnd.nextFloat() * 6.28f));
    }

    private Enemy nearby(float x, float y, float radius) {
        Enemy best = null;
        float bestD = radius * radius;
        for (Enemy e : enemies) {
            float dx = e.x - x;
            float dy = e.y - y;
            float d = dx * dx + dy * dy;
            if (d < bestD) {
                bestD = d;
                best = e;
            }
        }
        return best;
    }

    private void fire(float tx, float ty) {
        if (mode != GAME || caughtTimer > 0f || shotCooldown > 0f) return;

        if (ammo <= 0) {
            reload();
            return;
        }

        Enemy assisted = nearby(tx, ty, Math.min(getWidth(), getHeight()) * .14f);
        if (assisted != null) {
            tx = assisted.x;
            ty = assisted.y;
        }

        float dx = tx - px;
        float dy = ty - py;
        float d = (float)Math.hypot(dx, dy);
        if (d < 2f) return;

        float ux = dx / d;
        float uy = dy / d;
        rotation = (float)Math.toDegrees(Math.atan2(dy, dx)) - 180f;

        bullets.add(new Bullet(
                px + ux * 58f,
                py + uy * 58f,
                ux * BULLET_SPEED,
                uy * BULLET_SPEED
        ));

        ammo--;
        shotCooldown = .12f;
        crossX = tx;
        crossY = ty;
        crossTimer = .18f;
        sounds.play(sndShot, 1f, 1f, 2, 0, 1f);
    }

    private void reload() {
        if (mode == GAME && caughtTimer <= 0f && ammo < 2) {
            ammo = 2;
            sounds.play(sndReload, .9f, .9f, 1, 0, 1f);
        }
    }

    private void drawGame(Canvas c) {
        drawMap(c);
        for (Enemy e : enemies) drawEnemy(c, e);
        for (Bullet b : bullets) drawBullet(c, b);
        drawPlayer(c);
        if (crossTimer > 0f) drawCrosshair(c);
        if (caughtTimer > 0f) drawCaught(c);
        drawHud(c);
        drawTouchUi(c);
    }

    private void drawMap(Canvas c) {
        int w = getWidth(), h = getHeight();
        resetPaint();
        p.setColor(Color.rgb(151, 184, 72));
        c.drawRect(0, 0, w, h, p);

        if (tiles != null) c.drawBitmap(tiles, tile, new Rect(0, 0, w, h), p);

        if (env != null) {
            float u = Math.min(w, h);
            Rect[] srcs = {leaves, bush, grass, leaves, bush, grass, leaves, rock};
            float[] xs = {.05f, .16f, .30f, .50f, .68f, .83f, .95f, .73f};
            float[] ys = {.12f, .78f, .22f, .84f, .13f, .70f, .34f, .72f};

            for (int i = 0; i < srcs.length; i++) {
                float z = u * (i % 3 == 0 ? .27f : .16f);
                float x = xs[i] * w;
                float y = ys[i] * h;
                c.drawBitmap(env, srcs[i], new RectF(x - z/2, y - z/2, x + z/2, y + z/2), p);
            }
        }
    }

    private void drawPlayer(Canvas c) {
        resetPaint();
        float u = Math.min(getWidth(), getHeight());
        float w = u * .29f;
        float h = w * 116f / 206f;
        Rect src = ((int)(walkClock * 10f) & 1) == 0 ? player1 : player2;

        c.save();
        c.rotate(rotation, px, py);
        if (player != null) {
            c.drawBitmap(player, src,
                    new RectF(px - w/2, py - h/2, px + w/2, py + h/2), p);
        }
        c.restore();
    }

    private void drawEnemy(Canvas c, Enemy e) {
        resetPaint();
        float u = Math.min(getWidth(), getHeight());
        float w = u * .17f;
        float h = w * 113f / 140f;
        Rect src = ((int)e.phase & 1) == 0 ? enemy1 : enemy2;
        float angle = (float)Math.toDegrees(Math.atan2(py - e.y, px - e.x)) + 90f;

        c.save();
        c.rotate(angle, e.x, e.y);
        if (enemy != null) {
            c.drawBitmap(enemy, src,
                    new RectF(e.x - w/2, e.y - h/2, e.x + w/2, e.y + h/2), p);
        }
        c.restore();
    }

    private void drawBullet(Canvas c, Bullet b) {
        resetPaint();
        float d = (float)Math.hypot(b.vx, b.vy);
        float ux = b.vx / d, uy = b.vy / d;
        p.setColor(Color.YELLOW);
        p.setStrokeWidth(7f);
        p.setStrokeCap(Paint.Cap.ROUND);
        c.drawLine(b.x - ux * 30f, b.y - uy * 30f, b.x + ux * 30f, b.y + uy * 30f, p);
    }

    private void drawCrosshair(Canvas c) {
        resetPaint();
        float r = Math.min(getWidth(), getHeight()) * .028f;
        p.setStyle(Paint.Style.STROKE);
        p.setStrokeWidth(4f);
        p.setColor(Color.WHITE);
        c.drawCircle(crossX, crossY, r, p);
        c.drawLine(crossX-r*1.4f, crossY, crossX+r*1.4f, crossY, p);
        c.drawLine(crossX, crossY-r*1.4f, crossX, crossY+r*1.4f, p);
    }

    private void drawCaught(Canvas c) {
        float bounce = (float)Math.abs(Math.sin(caughtTimer * 22f));
        float u = Math.min(getWidth(), getHeight());
        float w = u * .22f;
        float h = w * 113f / 140f;
        float y = py - 18f - bounce * 28f;

        resetPaint();
        if (enemy != null) {
            c.drawBitmap(enemy, enemy1,
                    new RectF(px - w/2, y - h/2, px + w/2, y + h/2), p);
        }
        outlinedText(c, "CAUGHT!", px, py - w * .75f, u * .065f, Color.YELLOW, Paint.Align.CENTER);
    }

    private void drawHud(Canvas c) {
        int w = getWidth(), h = getHeight();
        float u = Math.min(w, h);

        for (int i = 0; i < 5; i++) {
            float r = u * .033f;
            float cx = u * .052f + i * r * 2.35f;
            float cy = u * .052f;

            resetPaint();
            p.setColor(i < lives ? Color.rgb(224,54,48) : Color.argb(70,255,255,255));
            c.drawCircle(cx, cy, r + 4f, p);

            if (face != null) {
                Path path = new Path();
                path.addCircle(cx, cy, r, Path.Direction.CW);
                c.save();
                c.clipPath(path);
                int fw = face.getWidth(), fh = face.getHeight();
                Rect src = new Rect((int)(fw*.16f), (int)(fh*.14f), (int)(fw*.84f), (int)(fh*.76f));
                c.drawBitmap(face, src, new RectF(cx-r, cy-r, cx+r, cy+r), p);
                c.restore();
            }
        }

        outlinedText(c, String.format("%05d", score),
                w - u*.055f, u*.075f, u*.070f, Color.WHITE, Paint.Align.RIGHT);

        outlinedText(c, "REMAINING",
                w - u*.22f, h - u*.105f, u*.036f, Color.WHITE, Paint.Align.RIGHT);

        for (int i = 0; i < 2; i++) {
            float x = w - u * (.18f - i*.055f);
            float y = h - u*.065f;
            resetPaint();
            p.setColor(i < ammo ? Color.RED : Color.argb(80,255,255,255));
            c.drawRoundRect(new RectF(x-u*.012f, y-u*.036f, x+u*.012f, y+u*.036f),
                    u*.008f, u*.008f, p);
            p.setColor(Color.rgb(248,211,65));
            c.drawRect(x-u*.012f, y+u*.017f, x+u*.012f, y+u*.036f, p);
        }
    }

    private void drawTouchUi(Canvas c) {
        float u = Math.min(getWidth(), getHeight());

        if (movePointer != -1) {
            float r = u * .105f;
            resetPaint();
            p.setStyle(Paint.Style.STROKE);
            p.setStrokeWidth(u * .008f);
            p.setColor(Color.argb(95,255,255,255));
            c.drawCircle(baseX, baseY, r, p);
            p.setStyle(Paint.Style.FILL);
            c.drawCircle(stickX, stickY, r*.38f, p);
        }

        resetPaint();
        p.setColor(Color.argb(95,20,20,20));
        c.drawOval(reloadButton, p);
        outlinedText(c, "R", reloadButton.centerX(),
                reloadButton.centerY() + reloadButton.height()*.16f,
                u*.055f, Color.WHITE, Paint.Align.CENTER);
    }

    private void drawGameOver(Canvas c) {
        resetPaint();
        p.setColor(Color.argb(205,0,0,0));
        c.drawRect(0,0,getWidth(),getHeight(),p);
        float u = Math.min(getWidth(),getHeight());

        outlinedText(c, "GAME OVER", getWidth()*.5f, getHeight()*.42f,
                u*.13f, Color.rgb(245,68,47), Paint.Align.CENTER);
        outlinedText(c, "SCORE " + score + "   RECORD " + record,
                getWidth()*.5f, getHeight()*.55f,
                u*.052f, Color.WHITE, Paint.Align.CENTER);
        outlinedText(c, "TAP TO PLAY AGAIN", getWidth()*.5f, getHeight()*.69f,
                u*.050f, Color.YELLOW, Paint.Align.CENTER);
    }

    private void outlinedText(Canvas c, String s, float x, float y, float size, int color, Paint.Align align) {
        text.setTextSize(size);
        text.setTextAlign(align);
        text.setTypeface(Typeface.DEFAULT_BOLD);
        text.setStyle(Paint.Style.STROKE);
        text.setStrokeWidth(Math.max(3f, size*.10f));
        text.setColor(Color.argb(190,20,20,20));
        c.drawText(s,x,y,text);

        text.setStyle(Paint.Style.FILL);
        text.setColor(color);
        c.drawText(s,x,y,text);
    }

    private boolean movementZone(float x, float y) {
        return x < getWidth() * .30f && y > getHeight() * .55f;
    }

    @Override
    public boolean onTouchEvent(MotionEvent e) {
        int action = e.getActionMasked();
        int index = e.getActionIndex();

        if (mode == MENU) {
            if (action == MotionEvent.ACTION_UP &&
                    (startButton.contains(e.getX(), e.getY()) || e.getY() > getHeight()*.50f)) {
                startGame();
            }
            return true;
        }

        if (mode == GAME_OVER) {
            if (action == MotionEvent.ACTION_UP) startGame();
            return true;
        }

        if (action == MotionEvent.ACTION_DOWN || action == MotionEvent.ACTION_POINTER_DOWN) {
            float x = e.getX(index);
            float y = e.getY(index);
            int id = e.getPointerId(index);

            if (reloadButton.contains(x,y)) {
                reload();
                return true;
            }

            Enemy hit = nearby(x, y, Math.min(getWidth(),getHeight())*.13f);
            if (hit != null) {
                fire(hit.x, hit.y);
                return true;
            }

            if (movementZone(x,y) && movePointer == -1) {
                movePointer = id;
                baseX = stickX = x;
                baseY = stickY = y;
                moveX = moveY = 0f;
            } else {
                fire(x,y);
            }
            return true;
        }

        if (action == MotionEvent.ACTION_MOVE && movePointer != -1) {
            int pIndex = e.findPointerIndex(movePointer);
            if (pIndex >= 0) updateStick(e.getX(pIndex), e.getY(pIndex));
            return true;
        }

        if (action == MotionEvent.ACTION_UP ||
                action == MotionEvent.ACTION_POINTER_UP ||
                action == MotionEvent.ACTION_CANCEL) {
            int id = e.getPointerId(index);
            if (id == movePointer || action == MotionEvent.ACTION_CANCEL) {
                movePointer = -1;
                moveX = moveY = 0f;
            }
            return true;
        }

        return true;
    }

    private void updateStick(float x, float y) {
        float r = Math.min(getWidth(), getHeight()) * .105f;
        float dx = x - baseX;
        float dy = y - baseY;
        float d = (float)Math.hypot(dx,dy);

        if (d > r && d > 0f) {
            dx = dx / d * r;
            dy = dy / d * r;
        }

        stickX = baseX + dx;
        stickY = baseY + dy;
        moveX = dx / r;
        moveY = dy / r;
    }

    private float clamp(float v, float lo, float hi) {
        return Math.max(lo, Math.min(hi, v));
    }
}
