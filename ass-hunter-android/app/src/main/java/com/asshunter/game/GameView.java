package com.asshunter.game;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.RectF;
import android.os.SystemClock;
import android.view.MotionEvent;
import android.view.View;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.Random;

public class GameView extends View {
    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random random = new Random();
    private final List<Bullet> bullets = new ArrayList<>();
    private final List<Target> targets = new ArrayList<>();
    private final RectF player = new RectF();

    private long lastFrame = SystemClock.elapsedRealtime();
    private float spawnTimer = 0f;
    private float playerX = 0f;
    private int score = 0;
    private int lives = 3;
    private float timeLeft = 60f;
    private boolean gameOver = false;
    private boolean leftPressed = false;
    private boolean rightPressed = false;

    private static final float BULLET_SPEED = 1000f;
    private static final float PLAYER_SPEED = 520f;

    public GameView(Context context) {
        super(context);
        paint.setTypeface(android.graphics.Typeface.create("sans", android.graphics.Typeface.NORMAL));
        setFocusable(true);
    }

    private static class Bullet {
        float x, y;
        final float r = 9f;
        Bullet(float x, float y) { this.x = x; this.y = y; }
    }

    private static class Target {
        float x, y, speed;
        final float r = 34f;
        Target(float x, float y, float speed) { this.x = x; this.y = y; this.speed = speed; }
    }

    @Override
    protected void onSizeChanged(int w, int h, int oldw, int oldh) {
        playerX = w / 2f;
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        long now = SystemClock.elapsedRealtime();
        float dt = Math.min(0.033f, (now - lastFrame) / 1000f);
        lastFrame = now;
        if (!gameOver) update(dt);
        drawGame(canvas);
        postInvalidateOnAnimation();
    }

    private void update(float dt) {
        if (leftPressed) playerX -= PLAYER_SPEED * dt;
        if (rightPressed) playerX += PLAYER_SPEED * dt;
        playerX = Math.max(55f, Math.min(getWidth() - 55f, playerX));

        timeLeft -= dt;
        if (timeLeft <= 0f || lives <= 0) {
            gameOver = true;
            return;
        }

        spawnTimer -= dt;
        if (spawnTimer <= 0f && getWidth() > 100) {
            spawnTimer = 0.45f + random.nextFloat() * 0.5f;
            float x = 45f + random.nextFloat() * Math.max(1f, getWidth() - 90f);
            float speed = 180f + random.nextFloat() * 180f;
            targets.add(new Target(x, -45f, speed));
        }

        Iterator<Bullet> bulletIterator = bullets.iterator();
        while (bulletIterator.hasNext()) {
            Bullet b = bulletIterator.next();
            b.y -= BULLET_SPEED * dt;
            if (b.y < -30f) bulletIterator.remove();
        }

        Iterator<Target> targetIterator = targets.iterator();
        while (targetIterator.hasNext()) {
            Target t = targetIterator.next();
            t.y += t.speed * dt;
            if (t.y > getHeight() + 50f) {
                lives--;
                targetIterator.remove();
            }
        }

        outer:
        for (int bi = bullets.size() - 1; bi >= 0; bi--) {
            Bullet b = bullets.get(bi);
            for (int ti = targets.size() - 1; ti >= 0; ti--) {
                Target t = targets.get(ti);
                float dx = b.x - t.x;
                float dy = b.y - t.y;
                float rr = b.r + t.r;
                if (dx * dx + dy * dy <= rr * rr) {
                    bullets.remove(bi);
                    targets.remove(ti);
                    score += 10;
                    continue outer;
                }
            }
        }
    }

    private void drawGame(Canvas canvas) {
        canvas.drawColor(Color.rgb(16, 24, 32));

        paint.setColor(Color.WHITE);
        paint.setTextSize(44f);
        paint.setFakeBoldText(true);
        canvas.drawText("ASS HUNTER", 28f, 58f, paint);

        paint.setTextSize(28f);
        paint.setFakeBoldText(false);
        canvas.drawText("Score: " + score, 28f, 100f, paint);
        canvas.drawText("Lives: " + lives, Math.max(28f, getWidth() - 160f), 100f, paint);
        canvas.drawText("Time: " + Math.max(0, (int) timeLeft), Math.max(28f, getWidth() / 2f - 60f), 135f, paint);

        for (Target t : targets) {
            paint.setColor(Color.rgb(255, 152, 0));
            canvas.drawCircle(t.x, t.y, t.r, paint);
            paint.setColor(Color.BLACK);
            canvas.drawCircle(t.x - 11f, t.y - 7f, 5f, paint);
            canvas.drawCircle(t.x + 11f, t.y - 7f, 5f, paint);
            paint.setStrokeWidth(5f);
            canvas.drawLine(t.x - 11f, t.y + 11f, t.x + 11f, t.y + 11f, paint);
        }

        paint.setColor(Color.YELLOW);
        for (Bullet b : bullets) canvas.drawCircle(b.x, b.y, b.r, paint);

        float py = getHeight() - 160f;
        player.set(playerX - 45f, py - 35f, playerX + 45f, py + 35f);
        paint.setColor(Color.rgb(70, 180, 230));
        canvas.drawRoundRect(player, 20f, 20f, paint);
        paint.setColor(Color.WHITE);
        canvas.drawRect(playerX - 7f, py - 65f, playerX + 7f, py - 20f, paint);

        paint.setColor(Color.argb(110, 255, 255, 255));
        canvas.drawRoundRect(24f, getHeight() - 100f, 180f, getHeight() - 24f, 20f, 20f, paint);
        canvas.drawRoundRect(getWidth() - 180f, getHeight() - 100f, getWidth() - 24f, getHeight() - 24f, 20f, 20f, paint);
        paint.setColor(Color.BLACK);
        paint.setTextSize(44f);
        canvas.drawText("<", 88f, getHeight() - 45f, paint);
        canvas.drawText(">", getWidth() - 112f, getHeight() - 45f, paint);

        paint.setColor(Color.LTGRAY);
        paint.setTextSize(22f);
        canvas.drawText("Tap upper area to shoot", 28f, getHeight() - 115f, paint);

        if (gameOver) {
            paint.setColor(Color.argb(215, 0, 0, 0));
            canvas.drawRect(0f, 0f, getWidth(), getHeight(), paint);
            paint.setColor(Color.WHITE);
            paint.setTextSize(54f);
            paint.setFakeBoldText(true);
            String title = "GAME OVER";
            canvas.drawText(title, getWidth() / 2f - paint.measureText(title) / 2f, getHeight() / 2f - 50f, paint);
            paint.setTextSize(34f);
            paint.setFakeBoldText(false);
            String scoreText = "Score: " + score;
            canvas.drawText(scoreText, getWidth() / 2f - paint.measureText(scoreText) / 2f, getHeight() / 2f + 10f, paint);
            String restart = "Tap to restart";
            canvas.drawText(restart, getWidth() / 2f - paint.measureText(restart) / 2f, getHeight() / 2f + 75f, paint);
        }
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        if (gameOver && event.getActionMasked() == MotionEvent.ACTION_DOWN) {
            restart();
            return true;
        }

        switch (event.getActionMasked()) {
            case MotionEvent.ACTION_DOWN:
            case MotionEvent.ACTION_POINTER_DOWN:
            case MotionEvent.ACTION_MOVE:
                leftPressed = false;
                rightPressed = false;
                for (int i = 0; i < event.getPointerCount(); i++) {
                    float x = event.getX(i);
                    float y = event.getY(i);
                    if (y > getHeight() - 120f) {
                        if (x < getWidth() / 2f) leftPressed = true;
                        else rightPressed = true;
                    }
                }
                if (event.getActionMasked() == MotionEvent.ACTION_DOWN && event.getY() < getHeight() - 180f) {
                    shoot();
                }
                break;
            case MotionEvent.ACTION_UP:
            case MotionEvent.ACTION_CANCEL:
                leftPressed = false;
                rightPressed = false;
                break;
            default:
                break;
        }
        return true;
    }

    private void shoot() {
        if (!gameOver && bullets.size() < 8) {
            bullets.add(new Bullet(playerX, getHeight() - 225f));
        }
    }

    private void restart() {
        bullets.clear();
        targets.clear();
        score = 0;
        lives = 3;
        timeLeft = 60f;
        spawnTimer = 0f;
        playerX = getWidth() / 2f;
        gameOver = false;
        lastFrame = SystemClock.elapsedRealtime();
        invalidate();
    }
}
