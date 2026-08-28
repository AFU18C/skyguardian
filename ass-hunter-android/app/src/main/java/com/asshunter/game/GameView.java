package com.asshunter.game;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.Rect;
import android.graphics.RectF;
import android.graphics.Typeface;
import android.os.SystemClock;
import android.util.Base64;
import android.view.KeyEvent;
import android.view.MotionEvent;
import android.view.View;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.Random;

/** Landscape, old-Flash-style top-down arcade recreation. */
public class GameView extends View {
    private static final float VW = 1000f;
    private static final float VH = 700f;
    private static final int MENU = 0;
    private static final int PLAY = 1;
    private static final int CAUGHT = 2;
    private static final int GAME_OVER = 3;

    private final Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint stroke = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random rnd = new Random();
    private final List<Enemy> enemies = new ArrayList<>();
    private final List<Shot> shots = new ArrayList<>();
    private final Bitmap portrait;

    private int state = MENU;
    private int score = 0;
    private int record = 0;
    private int lives = 5;
    private int ammo = 2;
    private float hunterX = 500f;
    private float hunterY = 365f;
    private float facingX = 0f;
    private float facingY = -1f;
    private float spawnTimer = .45f;
    private float caughtTimer = 0f;
    private long lastFrame = SystemClock.elapsedRealtime();
    private boolean left, right, up, down;
    private boolean touchFireDown;

    private final RectF startRect = new RectF(655, 540, 935, 625);
    private final RectF fireRect = new RectF(815, 530, 970, 675);
    private final RectF reloadRect = new RectF(820, 445, 965, 510);
    private final RectF leftRect = new RectF(35, 555, 120, 640);
    private final RectF rightRect = new RectF(205, 555, 290, 640);
    private final RectF upRect = new RectF(120, 505, 205, 590);
    private final RectF downRect = new RectF(120, 590, 205, 675);

    private static final float HUNTER_SPEED = 250f;
    private static final float SHOT_SPEED = 690f;
    private static final float CATCH_TIME = 1.45f;

    public GameView(Context context) {
        super(context);
        setFocusable(true);
        setFocusableInTouchMode(true);
        requestFocus();
        p.setTypeface(Typeface.create("sans-serif-condensed", Typeface.BOLD));
        stroke.setStyle(Paint.Style.STROKE);
        stroke.setStrokeWidth(4f);
        stroke.setStrokeJoin(Paint.Join.ROUND);
        stroke.setStrokeCap(Paint.Cap.ROUND);
        portrait = decode(EmbeddedImages.portraitBase64());
    }

    private Bitmap decode(String text) {
        try {
            byte[] b = Base64.decode(text, Base64.DEFAULT);
            return BitmapFactory.decodeByteArray(b, 0, b.length);
        } catch (Throwable t) {
            return null;
        }
    }

    private static class Enemy {
        float x, y, speed, wobble;
        Enemy(float x, float y, float speed, float wobble) {
            this.x = x; this.y = y; this.speed = speed; this.wobble = wobble;
        }
    }

    private static class Shot {
        float x, y, vx, vy;
        Shot(float x, float y, float vx, float vy) {
            this.x = x; this.y = y; this.vx = vx; this.vy = vy;
        }
    }

    @Override protected void onDraw(Canvas c) {
        super.onDraw(c);
        long now = SystemClock.elapsedRealtime();
        float dt = Math.min(.033f, (now - lastFrame) / 1000f);
        lastFrame = now;

        float s = Math.min(getWidth() / VW, getHeight() / VH);
        float ox = (getWidth() - VW * s) * .5f;
        float oy = (getHeight() - VH * s) * .5f;
        c.drawColor(Color.rgb(20, 20, 16));
        c.save();
        c.translate(ox, oy);
        c.scale(s, s);

        if (state == MENU) {
            drawMenu(c);
        } else {
            if (state == PLAY) updatePlay(dt);
            else if (state == CAUGHT) updateCaught(dt);
            drawPlayfield(c);
            if (state == CAUGHT) drawCaught(c);
            if (state == GAME_OVER) drawGameOver(c);
        }
        c.restore();
        postInvalidateOnAnimation();
    }

    private void updatePlay(float dt) {
        float mx = 0f, my = 0f;
        if (left) mx -= 1f;
        if (right) mx += 1f;
        if (up) my -= 1f;
        if (down) my += 1f;
        if (mx != 0f || my != 0f) {
            float len = (float)Math.sqrt(mx * mx + my * my);
            mx /= len; my /= len;
            hunterX += mx * HUNTER_SPEED * dt;
            hunterY += my * HUNTER_SPEED * dt;
            facingX = mx; facingY = my;
        }
        hunterX = clamp(hunterX, 95, 905);
        hunterY = clamp(hunterY, 110, 565);

        spawnTimer -= dt;
        if (spawnTimer <= 0f && enemies.size() < 8) {
            spawnTimer = .62f + rnd.nextFloat() * .50f;
            spawnEnemy();
        }

        Iterator<Shot> si = shots.iterator();
        while (si.hasNext()) {
            Shot sh = si.next();
            sh.x += sh.vx * dt;
            sh.y += sh.vy * dt;
            if (sh.x < -30 || sh.x > VW + 30 || sh.y < -30 || sh.y > VH + 30) si.remove();
        }

        for (Enemy e : enemies) {
            float dx = hunterX - e.x;
            float dy = hunterY - e.y;
            float d = (float)Math.sqrt(dx * dx + dy * dy);
            if (d < 1f) d = 1f;
            e.wobble += dt * 7f;
            float px = -dy / d;
            float py = dx / d;
            e.x += dx / d * e.speed * dt + px * (float)Math.sin(e.wobble) * 10f * dt;
            e.y += dy / d * e.speed * dt + py * (float)Math.sin(e.wobble) * 10f * dt;
            if (d < 44f) {
                state = CAUGHT;
                caughtTimer = 0f;
                left = right = up = down = false;
                shots.clear();
                return;
            }
        }

        outer:
        for (int a = shots.size() - 1; a >= 0; a--) {
            Shot sh = shots.get(a);
            for (int b = enemies.size() - 1; b >= 0; b--) {
                Enemy e = enemies.get(b);
                float dx = sh.x - e.x, dy = sh.y - e.y;
                if (dx * dx + dy * dy < 28f * 28f) {
                    shots.remove(a);
                    enemies.remove(b);
                    score += 100;
                    if (score > record) record = score;
                    continue outer;
                }
            }
        }
    }

    private void updateCaught(float dt) {
        caughtTimer += dt;
        if (caughtTimer >= CATCH_TIME) {
            lives--;
            enemies.clear();
            shots.clear();
            ammo = 2;
            hunterX = 500f;
            hunterY = 365f;
            facingX = 0f;
            facingY = -1f;
            spawnTimer = .75f;
            if (lives <= 0) state = GAME_OVER;
            else state = PLAY;
        }
    }

    private void spawnEnemy() {
        int side = rnd.nextInt(4);
        float x, y;
        if (side == 0) { x = 45; y = 105 + rnd.nextFloat() * 420; }
        else if (side == 1) { x = 955; y = 105 + rnd.nextFloat() * 420; }
        else if (side == 2) { x = 110 + rnd.nextFloat() * 780; y = 75; }
        else { x = 110 + rnd.nextFloat() * 780; y = 570; }
        enemies.add(new Enemy(x, y, 85 + rnd.nextFloat() * 45, rnd.nextFloat() * 6.28f));
    }

    private void shoot() {
        if (state != PLAY || ammo <= 0) return;
        ammo--;
        float len = (float)Math.sqrt(facingX * facingX + facingY * facingY);
        if (len < .1f) { facingX = 0; facingY = -1; len = 1; }
        float fx = facingX / len, fy = facingY / len;
        shots.add(new Shot(hunterX + fx * 34f, hunterY + fy * 34f, fx * SHOT_SPEED, fy * SHOT_SPEED));
    }

    private void reload() {
        if (state == PLAY) ammo = 2;
    }

    private void beginGame() {
        state = PLAY;
        score = 0;
        lives = 5;
        ammo = 2;
        enemies.clear();
        shots.clear();
        hunterX = 500f;
        hunterY = 365f;
        facingX = 0f;
        facingY = -1f;
        spawnTimer = .45f;
        caughtTimer = 0f;
        left = right = up = down = false;
    }

    private void drawMenu(Canvas c) {
        drawJungle(c, true);
        drawMenuHunter(c, 275, 390, 1.52f);

        p.setTextAlign(Paint.Align.CENTER);
        p.setColor(Color.rgb(190, 38, 29));
        p.setTypeface(Typeface.create("serif", Typeface.ITALIC));
        p.setTextSize(50);
        c.drawText("Watch out behind you", 700, 120, p);
        p.setTextSize(92);
        c.drawText("hunter!", 708, 202, p);

        p.setTypeface(Typeface.create("sans-serif-condensed", Typeface.BOLD));
        p.setColor(Color.rgb(255, 245, 0));
        p.setTextSize(40);
        c.drawText("RECORD: " + record, 675, 290, p);

        drawTargetIcon(c, 835, 390, 64);

        p.setColor(Color.rgb(255, 246, 0));
        p.setTextSize(50);
        p.setTextAlign(Paint.Align.CENTER);
        c.drawText("START GAME", startRect.centerX(), startRect.centerY() + 17, p);

        p.setColor(Color.argb(225, 255, 230, 30));
        c.drawRoundRect(new RectF(565, 450, 900, 520), 8, 8, p);
        p.setColor(Color.rgb(30, 55, 35));
        p.setTextSize(19);
        p.setTextAlign(Paint.Align.LEFT);
        c.drawText("ARROWS / D-PAD  -  MOVE", 585, 477, p);
        c.drawText("FIRE  -  SHOOT     RELOAD  -  2 SHELLS", 585, 505, p);
        p.setTextAlign(Paint.Align.LEFT);
    }

    private void drawPlayfield(Canvas c) {
        drawJungle(c, false);

        for (int i = 0; i < 5; i++) {
            float x = 38 + i * 59;
            if (i < lives) drawMiniLife(c, x, 34, 22, true);
            else drawMiniLife(c, x, 34, 22, false);
        }

        p.setTypeface(Typeface.create("sans-serif-condensed", Typeface.BOLD));
        p.setTextAlign(Paint.Align.RIGHT);
        p.setColor(Color.WHITE);
        p.setTextSize(30);
        c.drawText(String.format("%05d", score), 955, 52, p);

        for (Shot sh : shots) drawPellet(c, sh.x, sh.y);
        for (Enemy e : enemies) drawEnemyTopDown(c, e.x, e.y, e.wobble);
        drawHunterTopDown(c, hunterX, hunterY, facingX, facingY, 1f);

        p.setTextAlign(Paint.Align.RIGHT);
        p.setColor(Color.WHITE);
        p.setTextSize(18);
        c.drawText("REMAINING", 965, 610, p);
        for (int i = 0; i < 2; i++) drawShell(c, 880 + i * 38, 640, i < ammo);

        drawTouchControls(c);
        p.setTextAlign(Paint.Align.LEFT);
    }

    private void drawJungle(Canvas c, boolean menu) {
        p.setColor(Color.rgb(159, 190, 83));
        c.drawRect(0, 0, VW, VH, p);

        p.setColor(Color.rgb(184, 205, 105));
        for (int y = 90; y < 620; y += 70) {
            for (int x = 65 + ((y / 70) % 2) * 27; x < 960; x += 92) c.drawCircle(x, y, 5, p);
        }

        p.setColor(Color.rgb(202, 195, 145));
        Path edge = new Path();
        edge.moveTo(0, 80); edge.cubicTo(140, 55, 160, 120, 280, 75);
        edge.lineTo(330, 0); edge.lineTo(0, 0); edge.close(); c.drawPath(edge, p);
        Path bottom = new Path();
        bottom.moveTo(0, 630); bottom.cubicTo(170, 590, 275, 680, 430, 620);
        bottom.cubicTo(600, 560, 780, 665, 1000, 610); bottom.lineTo(1000, 700); bottom.lineTo(0,700); bottom.close(); c.drawPath(bottom,p);

        drawBushCluster(c, 70, 120, 1.15f);
        drawBushCluster(c, 930, 90, 1.05f);
        drawBushCluster(c, 80, 565, 1.2f);
        drawBushCluster(c, 920, 565, 1.25f);
        drawBushCluster(c, 505, 30, .85f);
        if (!menu) drawBushCluster(c, 500, 650, .78f);

        drawRock(c, 340, 122, 24);
        drawRock(c, 735, 570, 19);
        if (!menu) drawRock(c, 785, 170, 16);
    }

    private void drawBushCluster(Canvas c, float x, float y, float s) {
        p.setColor(Color.rgb(29, 82, 27));
        for (int i = 0; i < 11; i++) {
            double a = i * Math.PI * 2 / 11.0;
            c.drawCircle(x + (float)Math.cos(a) * 48 * s, y + (float)Math.sin(a) * 35 * s, 30 * s, p);
        }
        p.setColor(Color.rgb(55, 129, 37));
        for (int i = 0; i < 9; i++) {
            double a = i * Math.PI * 2 / 9.0 + .3;
            c.drawOval(new RectF(x - 12*s + (float)Math.cos(a)*45*s, y - 28*s + (float)Math.sin(a)*34*s,
                    x + 12*s + (float)Math.cos(a)*45*s, y + 28*s + (float)Math.sin(a)*34*s), p);
        }
        p.setColor(Color.rgb(101, 165, 55));
        c.drawCircle(x - 16*s, y - 12*s, 7*s, p);
        c.drawCircle(x + 24*s, y + 5*s, 6*s, p);
    }

    private void drawRock(Canvas c, float x, float y, float r) {
        p.setColor(Color.rgb(128, 124, 103));
        Path q = new Path();
        q.moveTo(x-r, y+4); q.lineTo(x-r*.55f,y-r*.7f); q.lineTo(x+r*.2f,y-r); q.lineTo(x+r,y-r*.15f); q.lineTo(x+r*.7f,y+r*.7f); q.lineTo(x-r*.25f,y+r); q.close();
        c.drawPath(q,p);
        stroke.setColor(Color.rgb(75,74,65)); stroke.setStrokeWidth(3); c.drawPath(q,stroke);
    }

    private void drawMenuHunter(Canvas c, float x, float y, float s) {
        c.save();
        c.translate(x, y);
        c.scale(s, s);

        p.setColor(Color.rgb(73, 70, 43));
        Path body = new Path(); body.moveTo(-105,-25); body.lineTo(-80,-150); body.lineTo(-35,-185); body.lineTo(40,-185); body.lineTo(86,-145); body.lineTo(112,15); body.lineTo(75,95); body.lineTo(-70,95); body.close(); c.drawPath(body,p);
        stroke.setColor(Color.rgb(52,42,25)); stroke.setStrokeWidth(5); c.drawPath(body,stroke);
        p.setColor(Color.rgb(116, 105, 62));
        c.drawOval(new RectF(-88,-128,-48,-90),p); c.drawOval(new RectF(40,-120,79,-79),p); c.drawOval(new RectF(-60,-50,-12,-12),p); c.drawOval(new RectF(22,-25,70,15),p);

        p.setColor(Color.rgb(92,83,47));
        Path col1=new Path(); col1.moveTo(-55,-162); col1.lineTo(-15,-130); col1.lineTo(-40,-80); col1.lineTo(-78,-135); col1.close(); c.drawPath(col1,p);
        Path col2=new Path(); col2.moveTo(52,-160); col2.lineTo(12,-130); col2.lineTo(40,-82); col2.lineTo(78,-135); col2.close(); c.drawPath(col2,p);

        RectF face = new RectF(-48,-250,48,-150);
        drawPortraitFace(c, face);
        stroke.setColor(Color.rgb(67,43,28)); stroke.setStrokeWidth(5); c.drawOval(face,stroke);

        stroke.setColor(Color.argb(190,55,35,24)); stroke.setStrokeWidth(4);
        c.drawLine(-31,-214,-8,-220,stroke); c.drawLine(31,-214,8,-220,stroke);
        c.drawLine(0,-211,-5,-184,stroke);

        p.setColor(Color.rgb(169,132,54)); c.drawOval(new RectF(-75,-280,72,-236),p);
        p.setColor(Color.rgb(183,143,58)); c.drawRoundRect(new RectF(-48,-315,45,-254),18,18,p);
        p.setColor(Color.rgb(77,58,31)); c.drawRect(-49,-271,47,-254,p);
        stroke.setColor(Color.rgb(72,49,28)); stroke.setStrokeWidth(5); c.drawOval(new RectF(-75,-280,72,-236),stroke); c.drawRoundRect(new RectF(-48,-315,45,-254),18,18,stroke);

        p.setColor(Color.rgb(220,161,115)); c.drawCircle(-47,-68,20,p); c.drawCircle(16,-52,20,p);
        p.setColor(Color.rgb(84,58,36)); c.drawRoundRect(new RectF(-56,-74,85,-44),10,10,p);
        p.setColor(Color.rgb(165,166,162));
        Path gunTop=new Path(); gunTop.moveTo(30,-72); gunTop.lineTo(182,-43); gunTop.lineTo(182,-18); gunTop.lineTo(25,-46); gunTop.close(); c.drawPath(gunTop,p);
        Path gunBot=new Path(); gunBot.moveTo(27,-47); gunBot.lineTo(180,-18); gunBot.lineTo(177,7); gunBot.lineTo(22,-22); gunBot.close(); c.drawPath(gunBot,p);
        stroke.setColor(Color.rgb(43,43,39)); stroke.setStrokeWidth(4); c.drawPath(gunTop,stroke); c.drawPath(gunBot,stroke);
        p.setColor(Color.rgb(40,40,36)); c.drawOval(new RectF(160,-49,195,-13),p); c.drawOval(new RectF(157,-23,192,14),p);
        p.setColor(Color.rgb(96,30,27)); for(int i=0;i<6;i++) c.drawRoundRect(new RectF(-75+i*27,56,-58+i*27,98),6,6,p);
        c.restore();
    }

    private void drawPortraitFace(Canvas c, RectF dst) {
        if (portrait == null) {
            p.setColor(Color.rgb(224,174,132)); c.drawOval(dst,p); return;
        }
        int w = portrait.getWidth(), h = portrait.getHeight();
        Rect src = new Rect((int)(w*.22f), (int)(h*.23f), (int)(w*.78f), (int)(h*.66f));
        c.save();
        Path clip = new Path(); clip.addOval(dst, Path.Direction.CW); c.clipPath(clip);
        c.drawBitmap(portrait, src, dst, p);
        c.restore();
    }

    private void drawTargetIcon(Canvas c, float cx, float cy, float r) {
        p.setColor(Color.rgb(236, 232, 200)); c.drawCircle(cx,cy,r,p);
        stroke.setColor(Color.rgb(25,28,28)); stroke.setStrokeWidth(6); c.drawCircle(cx,cy,r,stroke);
        p.setColor(Color.rgb(229, 188, 145)); c.drawOval(new RectF(cx-r*.45f,cy-r*.32f,cx-r*.03f,cy+r*.40f),p); c.drawOval(new RectF(cx+r*.03f,cy-r*.32f,cx+r*.45f,cy+r*.40f),p);
        p.setColor(Color.rgb(55,84,121)); c.drawRect(cx-r*.48f,cy-r*.36f,cx+r*.48f,cy-r*.16f,p);
        stroke.setColor(Color.RED); stroke.setStrokeWidth(4); c.drawCircle(cx,cy+3,r*.28f,stroke); c.drawLine(cx-r*.38f,cy+3,cx+r*.38f,cy+3,stroke); c.drawLine(cx,cy-r*.38f,cx,cy+r*.42f,stroke);
    }

    private void drawHunterTopDown(Canvas c, float x, float y, float fx, float fy, float s) {
        c.save(); c.translate(x,y); float ang=(float)Math.toDegrees(Math.atan2(fy,fx))+90f; c.rotate(ang); c.scale(s,s);
        p.setColor(Color.argb(70,0,0,0)); c.drawOval(new RectF(-30,16,30,42),p);
        p.setColor(Color.rgb(52,52,34)); c.drawOval(new RectF(-27,-5,27,42),p);
        p.setColor(Color.rgb(90,85,49)); c.drawRoundRect(new RectF(-34,-31,34,18),15,15,p);
        p.setColor(Color.rgb(119,107,58)); c.drawOval(new RectF(-48,-28,-18,13),p); c.drawOval(new RectF(18,-28,48,13),p);
        RectF face=new RectF(-22,-56,22,-18); drawPortraitFace(c,face);
        p.setColor(Color.rgb(171,132,54)); c.drawOval(new RectF(-33,-65,33,-38),p); p.setColor(Color.rgb(79,58,31)); c.drawRect(-24,-55,24,-48,p);
        p.setColor(Color.rgb(65,46,30)); c.drawRoundRect(new RectF(-7,-88,7,-18),5,5,p);
        p.setColor(Color.rgb(170,170,161)); c.drawRoundRect(new RectF(-12,-116,-1,-61),4,4,p); c.drawRoundRect(new RectF(1,-116,12,-61),4,4,p);
        c.restore();
    }

    private void drawEnemyTopDown(Canvas c, float x, float y, float phase) {
        c.save(); c.translate(x,y); c.rotate((float)Math.sin(phase)*7f);
        p.setColor(Color.argb(55,0,0,0)); c.drawOval(new RectF(-24,14,24,35),p);
        p.setColor(Color.rgb(232,187,143)); c.drawCircle(0,-23,13,p);
        p.setColor(Color.rgb(39,88,145)); c.drawRoundRect(new RectF(-17,-14,17,17),8,8,p);
        p.setColor(Color.rgb(64,45,31)); c.drawOval(new RectF(-20,7,20,28),p);
        stroke.setColor(Color.rgb(38,32,25)); stroke.setStrokeWidth(5);
        float leg=(float)Math.sin(phase*2f)*10f;
        c.drawLine(-8,22,-15-leg,42,stroke); c.drawLine(8,22,15+leg,42,stroke);
        c.restore();
    }

    private void drawCaught(Canvas c) {
        float bounce = (float)Math.sin(caughtTimer * 30f) * 9f;
        float ex = hunterX + 8f;
        float ey = hunterY - 28f + bounce;
        drawEnemyTopDown(c, ex, ey, caughtTimer * 14f);
        p.setColor(Color.rgb(92,60,37));
        c.drawOval(new RectF(ex-30, ey+4, ex+30, ey+35), p);
        p.setColor(Color.WHITE); p.setTypeface(Typeface.create("sans-serif-condensed",Typeface.BOLD)); p.setTextAlign(Paint.Align.CENTER); p.setTextSize(42);
        c.drawText("WATCH YOUR BACK!", 500, 105, p);
        p.setColor(Color.rgb(255,235,40));
        for(int i=0;i<6;i++){ double a=i*Math.PI/3; c.drawCircle(hunterX+(float)Math.cos(a)*58,hunterY+(float)Math.sin(a)*45,7,p); }
    }

    private void drawGameOver(Canvas c) {
        p.setColor(Color.argb(190, 25, 20, 15)); c.drawRect(0,0,VW,VH,p);
        p.setTextAlign(Paint.Align.CENTER); p.setTypeface(Typeface.create("serif",Typeface.BOLD)); p.setColor(Color.rgb(190,38,29)); p.setTextSize(95); c.drawText("GAME OVER",500,280,p);
        p.setTypeface(Typeface.create("sans-serif-condensed",Typeface.BOLD)); p.setColor(Color.rgb(255,244,0)); p.setTextSize(42); c.drawText("SCORE: "+score,500,355,p); p.setTextSize(34); c.drawText("TAP TO PLAY AGAIN",500,440,p);
        p.setTextAlign(Paint.Align.LEFT);
    }

    private void drawTouchControls(Canvas c) {
        drawTouchButton(c,leftRect,"◀",left); drawTouchButton(c,rightRect,"▶",right); drawTouchButton(c,upRect,"▲",up); drawTouchButton(c,downRect,"▼",down);
        p.setColor(Color.argb(125,244,75,38)); c.drawRoundRect(fireRect,18,18,p); stroke.setColor(Color.argb(190,90,28,17)); stroke.setStrokeWidth(4); c.drawRoundRect(fireRect,18,18,stroke);
        p.setColor(Color.WHITE); p.setTextAlign(Paint.Align.CENTER); p.setTextSize(34); c.drawText("FIRE",fireRect.centerX(),fireRect.centerY()+12,p);
        p.setColor(Color.argb(125,245,235,205)); c.drawRoundRect(reloadRect,12,12,p); p.setColor(Color.rgb(35,35,25)); p.setTextSize(24); c.drawText(ammo==2?"LOADED":"RELOAD",reloadRect.centerX(),reloadRect.centerY()+8,p);
    }

    private void drawTouchButton(Canvas c, RectF r, String txt, boolean pressed) {
        p.setColor(Color.argb(pressed?190:115,245,245,235)); c.drawRoundRect(r,18,18,p);
        p.setColor(Color.rgb(32,45,31)); p.setTextAlign(Paint.Align.CENTER); p.setTextSize(33); c.drawText(txt,r.centerX(),r.centerY()+12,p);
    }

    private void drawMiniLife(Canvas c, float x, float y, float r, boolean active) {
        p.setColor(active?Color.rgb(238,235,205):Color.argb(80,238,235,205)); c.drawCircle(x,y,r,p);
        stroke.setColor(active?Color.rgb(220,45,37):Color.argb(70,220,45,37)); stroke.setStrokeWidth(5); c.drawCircle(x,y,r,stroke);
        p.setColor(active?Color.rgb(227,182,140):Color.argb(70,227,182,140)); c.drawOval(new RectF(x-r*.48f,y-r*.20f,x-r*.03f,y+r*.42f),p); c.drawOval(new RectF(x+r*.03f,y-r*.20f,x+r*.48f,y+r*.42f),p);
    }

    private void drawShell(Canvas c, float x, float y, boolean live) {
        p.setColor(live?Color.rgb(179,44,35):Color.rgb(90,86,73)); c.drawRoundRect(new RectF(x-9,y-22,x+9,y+20),5,5,p); p.setColor(live?Color.rgb(229,184,62):Color.rgb(125,120,99)); c.drawRect(x-9,y+12,x+9,y+22,p);
    }

    private void drawPellet(Canvas c,float x,float y){ p.setColor(Color.rgb(255,230,75)); c.drawCircle(x,y,5,p); stroke.setColor(Color.rgb(70,55,20)); stroke.setStrokeWidth(2); c.drawCircle(x,y,5,stroke); }
    private float clamp(float v,float lo,float hi){ return Math.max(lo,Math.min(hi,v)); }

    private float localX(MotionEvent e, int i) {
        float s=Math.min(getWidth()/VW,getHeight()/VH); float ox=(getWidth()-VW*s)*.5f; return (e.getX(i)-ox)/s;
    }
    private float localY(MotionEvent e, int i) {
        float s=Math.min(getWidth()/VW,getHeight()/VH); float oy=(getHeight()-VH*s)*.5f; return (e.getY(i)-oy)/s;
    }

    @Override public boolean onTouchEvent(MotionEvent e) {
        int act=e.getActionMasked();
        if (act==MotionEvent.ACTION_DOWN && state==MENU) {
            float x=localX(e,0),y=localY(e,0); if(startRect.contains(x,y)){ beginGame(); return true; }
        }
        if (act==MotionEvent.ACTION_DOWN && state==GAME_OVER) { beginGame(); return true; }
        if (state!=PLAY) return true;

        left=right=up=down=false;
        boolean fireNow=false;
        for(int i=0;i<e.getPointerCount();i++){
            float x=localX(e,i), y=localY(e,i);
            if(leftRect.contains(x,y)) left=true;
            if(rightRect.contains(x,y)) right=true;
            if(upRect.contains(x,y)) up=true;
            if(downRect.contains(x,y)) down=true;
            if(fireRect.contains(x,y)) fireNow=true;
            if((act==MotionEvent.ACTION_DOWN||act==MotionEvent.ACTION_POINTER_DOWN) && reloadRect.contains(x,y)) reload();
        }
        if(fireNow && !touchFireDown) shoot();
        touchFireDown=fireNow;
        if(act==MotionEvent.ACTION_UP||act==MotionEvent.ACTION_CANCEL){left=right=up=down=false;touchFireDown=false;}
        return true;
    }

    @Override public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (state==MENU && (keyCode==KeyEvent.KEYCODE_ENTER || keyCode==KeyEvent.KEYCODE_SPACE)) { beginGame(); return true; }
        if (state==GAME_OVER && (keyCode==KeyEvent.KEYCODE_ENTER || keyCode==KeyEvent.KEYCODE_SPACE)) { beginGame(); return true; }
        if(state!=PLAY) return super.onKeyDown(keyCode,event);
        if(keyCode==KeyEvent.KEYCODE_DPAD_LEFT){left=true;return true;}
        if(keyCode==KeyEvent.KEYCODE_DPAD_RIGHT){right=true;return true;}
        if(keyCode==KeyEvent.KEYCODE_DPAD_UP){up=true;return true;}
        if(keyCode==KeyEvent.KEYCODE_DPAD_DOWN){down=true;return true;}
        if(keyCode==KeyEvent.KEYCODE_SPACE){shoot();return true;}
        if(keyCode==KeyEvent.KEYCODE_CTRL_LEFT||keyCode==KeyEvent.KEYCODE_CTRL_RIGHT||keyCode==KeyEvent.KEYCODE_ENTER){reload();return true;}
        return super.onKeyDown(keyCode,event);
    }

    @Override public boolean onKeyUp(int keyCode, KeyEvent event) {
        if(keyCode==KeyEvent.KEYCODE_DPAD_LEFT){left=false;return true;}
        if(keyCode==KeyEvent.KEYCODE_DPAD_RIGHT){right=false;return true;}
        if(keyCode==KeyEvent.KEYCODE_DPAD_UP){up=false;return true;}
        if(keyCode==KeyEvent.KEYCODE_DPAD_DOWN){down=false;return true;}
        return super.onKeyUp(keyCode,event);
    }
}
