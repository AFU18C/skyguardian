package com.asshunter.game;

import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.*;
import android.media.AudioAttributes;
import android.media.SoundPool;
import android.os.SystemClock;
import android.view.MotionEvent;
import android.view.View;

import java.util.ArrayList;
import java.util.List;
import java.util.Random;

public class GameViewV8 extends View {
    private static final int MENU=0, GAME=1, OVER=2;
    private final Paint p=new Paint(Paint.ANTI_ALIAS_FLAG|Paint.FILTER_BITMAP_FLAG);
    private final Paint txt=new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random rnd=new Random();
    private final List<Enemy> enemies=new ArrayList<>();
    private final List<Bullet> bullets=new ArrayList<>();
    private final SharedPreferences prefs;

    private final Bitmap player,enemy,tiles,env;
    private final Rect player1=new Rect(0,0,206,116), player2=new Rect(206,0,412,116);
    private final Rect enemy1=new Rect(420,339,560,452), enemy2=new Rect(560,339,700,452);
    private final Rect tile=new Rect(323,219,647,438);
    private final Rect leaves=new Rect(1,1,168,159), bush=new Rect(170,1,283,113),
            rock=new Rect(285,31,332,77), grass=new Rect(170,115,231,167);

    private final RectF start=new RectF(), reload=new RectF();
    private final SoundPool sounds;
    private final int sShot,sReload,sHit,sCatch;

    private int mode=MENU,score=0,record,lives=5,ammo=2;
    private float px,py,rotation,spawn=.7f,cooldown,walk,caught,crossX,crossY,crossT;
    private int movePointer=-1;
    private float baseX,baseY,stickX,stickY,moveX,moveY;
    private long last=SystemClock.elapsedRealtime();

    private static final float PLAYER_SPEED=500f;
    private static final float ENEMY_MIN=110f;
    private static final float ENEMY_MAX=160f;
    private static final float BULLET_SPEED=1800f;

    private static class Enemy {
        float x,y,speed,phase;
        Enemy(float x,float y,float speed,float phase){this.x=x;this.y=y;this.speed=speed;this.phase=phase;}
    }
    private static class Bullet {
        float x,y,vx,vy;
        Bullet(float x,float y,float vx,float vy){this.x=x;this.y=y;this.vx=vx;this.vy=vy;}
    }

    public GameViewV8(Context c){
        super(c);
        setFocusable(true);
        txt.setTypeface(Typeface.DEFAULT_BOLD);
        player=BitmapFactory.decodeResource(getResources(),R.drawable.player);
        enemy=BitmapFactory.decodeResource(getResources(),R.drawable.enemy);
        tiles=BitmapFactory.decodeResource(getResources(),R.drawable.tiles);
        env=BitmapFactory.decodeResource(getResources(),R.drawable.environment);
        prefs=c.getSharedPreferences("ass_hunter_hd",Context.MODE_PRIVATE);
        record=prefs.getInt("record",0);
        AudioAttributes a=new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_GAME)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build();
        sounds=new SoundPool.Builder().setMaxStreams(4).setAudioAttributes(a).build();
        sShot=sounds.load(c,R.raw.shot,1);
        sReload=sounds.load(c,R.raw.reload,1);
        sHit=sounds.load(c,R.raw.hit,1);
        sCatch=sounds.load(c,R.raw.catch_sound,1);
    }

    private void reset(){
        p.setAlpha(255); p.setShader(null); p.setStyle(Paint.Style.FILL);
        p.setStrokeWidth(1f); p.setStrokeCap(Paint.Cap.BUTT); p.setColor(Color.WHITE);
    }

    @Override protected void onSizeChanged(int w,int h,int ow,int oh){
        px=w*.5f; py=h*.52f;
        start.set(w*.59f,h*.58f,w*.93f,h*.82f);
        float r=Math.min(w,h)*.065f;
        reload.set(w-r*2.35f,h-r*2.2f,w-r*.35f,h-r*.2f);
    }

    @Override protected void onDraw(Canvas c){
        long now=SystemClock.elapsedRealtime();
        float dt=Math.min(.033f,Math.max(0,(now-last)/1000f));
        last=now;
        if(mode==MENU) drawMenu(c);
        else{
            if(mode==GAME) update(dt);
            drawGame(c);
            if(mode==OVER) drawOver(c);
        }
        postInvalidateOnAnimation();
    }

    private void drawMenu(Canvas c){
        int w=getWidth(),h=getHeight(); float u=Math.min(w,h);
        reset();
        p.setShader(new LinearGradient(0,0,0,h,Color.rgb(187,226,246),Color.rgb(209,232,207),Shader.TileMode.CLAMP));
        c.drawRect(0,0,w,h,p); p.setShader(null);

        p.setColor(Color.rgb(68,112,78));
        for(int i=-1;i<19;i++){
            float x=i*w/17f,base=h*.63f,hh=h*(.16f+(i%4)*.02f);
            Path t=new Path(); t.moveTo(x,base-hh); t.lineTo(x-hh*.22f,base); t.lineTo(x+hh*.22f,base); t.close();
            c.drawPath(t,p);
        }

        p.setColor(Color.rgb(101,150,76)); c.drawRect(0,h*.58f,w,h,p);
        for(int i=0;i<120;i++){
            float x=(i*73f)%(w+30)-15, tip=h*(.52f+((i*29)%33)/100f);
            p.setColor((i&1)==0?Color.rgb(44,108,50):Color.rgb(66,132,58));
            p.setStrokeWidth(2+(i%3));
            c.drawLine(x,h,x+((i%5)-2)*6,tip,p);
        }

        drawHunter(c,w*.22f,h*.93f,h*.82f);

        RectF sign=new RectF(w*.43f,h*.07f,w*.94f,h*.31f);
        reset(); p.setColor(Color.rgb(111,66,30)); c.drawRoundRect(sign,u*.025f,u*.025f,p);
        p.setStyle(Paint.Style.STROKE); p.setStrokeWidth(u*.008f); p.setColor(Color.rgb(56,35,18));
        c.drawRoundRect(sign,u*.025f,u*.025f,p);
        outlined(c,"ASS HUNTER",sign.centerX(),sign.centerY()+u*.035f,u*.105f,Color.rgb(255,215,47),Paint.Align.CENTER);

        outlined(c,"WATCH OUT BEHIND YOU HUNTER!",w*.70f,h*.40f,u*.043f,Color.rgb(231,58,44),Paint.Align.CENTER);
        outlined(c,"RECORD: "+record,w*.80f,h*.50f,u*.050f,Color.rgb(255,219,42),Paint.Align.CENTER);

        reset(); p.setColor(Color.rgb(126,74,35)); c.drawRoundRect(start,u*.025f,u*.025f,p);
        p.setStyle(Paint.Style.STROKE); p.setStrokeWidth(u*.008f); p.setColor(Color.rgb(58,35,19));
        c.drawRoundRect(start,u*.025f,u*.025f,p);
        outlined(c,"START GAME",start.centerX(),start.centerY()+u*.025f,u*.070f,Color.WHITE,Paint.Align.CENTER);
    }

    private void drawHunter(Canvas c,float cx,float ground,float height){
        float s=height/500f, lw=Math.max(2,4*s);
        reset();
        p.setColor(Color.argb(55,0,0,0));
        c.drawOval(new RectF(cx-95*s,ground-10*s,cx+105*s,ground+15*s),p);

        p.setColor(Color.rgb(83,66,44));
        c.drawRoundRect(new RectF(cx-58*s,ground-155*s,cx-10*s,ground-12*s),14*s,14*s,p);
        c.drawRoundRect(new RectF(cx+10*s,ground-155*s,cx+58*s,ground-12*s),14*s,14*s,p);
        p.setColor(Color.rgb(82,52,29));
        c.drawRoundRect(new RectF(cx-70*s,ground-35*s,cx-5*s,ground),12*s,12*s,p);
        c.drawRoundRect(new RectF(cx+4*s,ground-35*s,cx+70*s,ground),12*s,12*s,p);

        RectF body=new RectF(cx-92*s,ground-345*s,cx+92*s,ground-142*s);
        p.setColor(Color.rgb(101,88,49)); c.drawRoundRect(body,30*s,30*s,p);
        p.setStyle(Paint.Style.STROKE); p.setStrokeWidth(lw); p.setColor(Color.rgb(48,42,29)); c.drawRoundRect(body,30*s,30*s,p);
        p.setStyle(Paint.Style.FILL);

        int[] cam={Color.rgb(75,73,40),Color.rgb(125,105,55),Color.rgb(63,78,42)};
        float[][] patches={{-68,-320,-27,-286},{18,-330,68,-294},{-80,-258,-42,-220},{-20,-275,27,-236},{32,-238,79,-198},{-53,-192,-8,-160}};
        for(int i=0;i<patches.length;i++){
            float[] a=patches[i]; p.setColor(cam[i%3]);
            c.drawOval(new RectF(cx+a[0]*s,ground+a[1]*s,cx+a[2]*s,ground+a[3]*s),p);
        }

        p.setColor(Color.rgb(219,164,118));
        c.drawRoundRect(new RectF(cx-24*s,ground-380*s,cx+24*s,ground-337*s),12*s,12*s,p);

        RectF head=new RectF(cx-53*s,ground-453*s,cx+53*s,ground-350*s);
        p.setColor(Color.rgb(231,176,129)); c.drawOval(head,p);
        p.setStyle(Paint.Style.STROKE); p.setStrokeWidth(lw); p.setColor(Color.rgb(65,45,31)); c.drawOval(head,p);
        p.setStyle(Paint.Style.FILL);

        p.setColor(Color.rgb(70,55,46));
        c.drawArc(new RectF(cx-48*s,ground-448*s,cx+48*s,ground-375*s),190,160,true,p);

        p.setStyle(Paint.Style.STROKE); p.setStrokeWidth(5*s); p.setStrokeCap(Paint.Cap.ROUND); p.setColor(Color.rgb(62,46,37));
        c.drawLine(cx-31*s,ground-414*s,cx-10*s,ground-417*s,p);
        c.drawLine(cx+10*s,ground-417*s,cx+31*s,ground-414*s,p);
        p.setStyle(Paint.Style.FILL);

        p.setColor(Color.WHITE);
        c.drawOval(new RectF(cx-32*s,ground-411*s,cx-8*s,ground-396*s),p);
        c.drawOval(new RectF(cx+8*s,ground-411*s,cx+32*s,ground-396*s),p);
        p.setColor(Color.rgb(70,69,49));
        c.drawCircle(cx-18*s,ground-403*s,5*s,p); c.drawCircle(cx+18*s,ground-403*s,5*s,p);
        p.setColor(Color.BLACK);
        c.drawCircle(cx-18*s,ground-403*s,2*s,p); c.drawCircle(cx+18*s,ground-403*s,2*s,p);

        p.setColor(Color.rgb(68,54,46));
        c.drawOval(new RectF(cx-29*s,ground-383*s,cx-1*s,ground-370*s),p);
        c.drawOval(new RectF(cx+1*s,ground-383*s,cx+29*s,ground-370*s),p);
        Path beard=new Path(); beard.moveTo(cx-31*s,ground-372*s);
        beard.quadTo(cx-28*s,ground-343*s,cx,ground-337*s);
        beard.quadTo(cx+28*s,ground-343*s,cx+31*s,ground-372*s);
        beard.quadTo(cx,ground-354*s,cx-31*s,ground-372*s); beard.close(); c.drawPath(beard,p);

        p.setColor(Color.rgb(148,135,124));
        for(int i=-2;i<=2;i++) c.drawCircle(cx+i*9*s,ground-(350+Math.abs(i)*2)*s,2.2f*s,p);

        p.setStyle(Paint.Style.STROKE); p.setStrokeWidth(3*s); p.setColor(Color.rgb(105,52,43));
        c.drawArc(new RectF(cx-18*s,ground-376*s,cx+18*s,ground-358*s),10,160,false,p);
        p.setStyle(Paint.Style.FILL);

        p.setColor(Color.rgb(134,91,39));
        c.drawOval(new RectF(cx-74*s,ground-476*s,cx+74*s,ground-447*s),p);
        Path hat=new Path(); hat.moveTo(cx-46*s,ground-457*s); hat.lineTo(cx-36*s,ground-500*s);
        hat.quadTo(cx,ground-516*s,cx+37*s,ground-500*s); hat.lineTo(cx+47*s,ground-457*s); hat.close(); c.drawPath(hat,p);
        p.setColor(Color.rgb(80,52,27)); c.drawRect(cx-43*s,ground-467*s,cx+43*s,ground-457*s,p);

        p.setColor(Color.rgb(101,88,49));
        c.drawOval(new RectF(cx-112*s,ground-325*s,cx-45*s,ground-215*s),p);
        c.drawOval(new RectF(cx+42*s,ground-315*s,cx+111*s,ground-210*s),p);
        p.setColor(Color.rgb(228,170,122));
        c.drawCircle(cx-55*s,ground-245*s,18*s,p); c.drawCircle(cx+50*s,ground-239*s,18*s,p);

        p.setColor(Color.rgb(110,57,28));
        Path stock=new Path(); stock.moveTo(cx-75*s,ground-245*s); stock.lineTo(cx-130*s,ground-202*s);
        stock.lineTo(cx-116*s,ground-184*s); stock.lineTo(cx-36*s,ground-222*s); stock.close(); c.drawPath(stock,p);

        p.setColor(Color.rgb(105,103,91));
        c.drawRoundRect(new RectF(cx-48*s,ground-257*s,cx+14*s,ground-221*s),5*s,5*s,p);

        c.save(); c.rotate(12,cx+15*s,ground-236*s);
        p.setColor(Color.rgb(205,208,207));
        c.drawRoundRect(new RectF(cx+5*s,ground-254*s,cx+167*s,ground-239*s),7*s,7*s,p);
        c.drawRoundRect(new RectF(cx+5*s,ground-236*s,cx+167*s,ground-221*s),7*s,7*s,p);
        p.setColor(Color.rgb(24,26,27));
        c.drawOval(new RectF(cx+152*s,ground-254*s,cx+171*s,ground-239*s),p);
        c.drawOval(new RectF(cx+152*s,ground-236*s,cx+171*s,ground-221*s),p);
        c.restore();

        p.setColor(Color.rgb(58,43,25)); c.drawRect(cx-70*s,ground-202*s,cx+70*s,ground-184*s,p);
        for(int i=-5;i<=5;i++){
            float x=cx+i*12*s; p.setColor(Color.rgb(164,42,31));
            c.drawRoundRect(new RectF(x-4*s,ground-208*s,x+4*s,ground-180*s),3*s,3*s,p);
            p.setColor(Color.rgb(223,185,59)); c.drawRect(x-4*s,ground-185*s,x+4*s,ground-180*s,p);
        }
    }

    private void startGame(){
        mode=GAME; score=0; lives=5; ammo=2; enemies.clear(); bullets.clear();
        px=getWidth()*.5f; py=getHeight()*.52f; rotation=0; spawn=.65f; cooldown=walk=caught=crossT=0;
        movePointer=-1; moveX=moveY=0; last=SystemClock.elapsedRealtime();
    }

    private void update(float dt){
        crossT=Math.max(0,crossT-dt);
        if(caught>0){
            caught+=dt;
            if(caught>=1f){caught=0;enemies.clear();bullets.clear();lives--;spawn=.7f;if(lives<=0)mode=OVER;}
            return;
        }

        cooldown=Math.max(0,cooldown-dt); walk+=dt;
        px=clamp(px+moveX*PLAYER_SPEED*dt,getWidth()*.065f,getWidth()*.935f);
        py=clamp(py+moveY*PLAYER_SPEED*dt,getHeight()*.10f,getHeight()*.90f);

        spawn-=dt;
        if(spawn<=0 && enemies.size()<5){
            spawnEnemy();
            spawn=Math.max(.50f,.78f-Math.min(.24f,score/22000f))+rnd.nextFloat()*.30f;
        }

        for(int i=bullets.size()-1;i>=0;i--){
            Bullet b=bullets.get(i); b.x+=b.vx*dt; b.y+=b.vy*dt;
            if(b.x<-100||b.y<-100||b.x>getWidth()+100||b.y>getHeight()+100)bullets.remove(i);
        }

        for(Enemy e:enemies){
            float dx=px-e.x,dy=py-e.y,d=(float)Math.hypot(dx,dy);
            if(d<Math.min(getWidth(),getHeight())*.07f){caught=.001f;moveX=moveY=0;sounds.play(sCatch,.8f,.8f,1,0,1);break;}
            if(d>1){e.x+=dx/d*e.speed*dt;e.y+=dy/d*e.speed*dt;} e.phase+=dt*9f;
        }

        if(caught<=0){
            float r=Math.min(getWidth(),getHeight())*.11f;
            outer: for(int i=bullets.size()-1;i>=0;i--){
                Bullet b=bullets.get(i);
                for(int j=enemies.size()-1;j>=0;j--){
                    Enemy e=enemies.get(j); float dx=b.x-e.x,dy=b.y-e.y;
                    if(dx*dx+dy*dy<r*r){
                        bullets.remove(i); enemies.remove(j); score+=100;
                        if(score>record){record=score;prefs.edit().putInt("record",record).apply();}
                        sounds.play(sHit,.8f,.8f,1,0,1); continue outer;
                    }
                }
            }
        }
    }

    private void spawnEnemy(){
        int side=rnd.nextInt(4);float x,y,pad=90;
        if(side==0){x=-pad;y=getHeight()*(.15f+rnd.nextFloat()*.7f);}
        else if(side==1){x=getWidth()+pad;y=getHeight()*(.15f+rnd.nextFloat()*.7f);}
        else if(side==2){x=getWidth()*(.1f+rnd.nextFloat()*.8f);y=-pad;}
        else{x=getWidth()*(.1f+rnd.nextFloat()*.8f);y=getHeight()+pad;}
        float sp=ENEMY_MIN+rnd.nextFloat()*(ENEMY_MAX-ENEMY_MIN)+Math.min(55,score/80f);
        enemies.add(new Enemy(x,y,sp,rnd.nextFloat()*6.28f));
    }

    private Enemy near(float x,float y,float r){
        Enemy best=null;float bd=r*r;
        for(Enemy e:enemies){float dx=e.x-x,dy=e.y-y,d=dx*dx+dy*dy;if(d<bd){bd=d;best=e;}}
        return best;
    }

    private void fire(float tx,float ty){
        if(mode!=GAME||caught>0||cooldown>0)return;
        if(ammo<=0){reload();return;}
        Enemy a=near(tx,ty,Math.min(getWidth(),getHeight())*.14f);if(a!=null){tx=a.x;ty=a.y;}
        float dx=tx-px,dy=ty-py,d=(float)Math.hypot(dx,dy);if(d<2)return;
        float ux=dx/d,uy=dy/d;
        rotation=(float)Math.toDegrees(Math.atan2(dy,dx))-180f;
        bullets.add(new Bullet(px+ux*58,py+uy*58,ux*BULLET_SPEED,uy*BULLET_SPEED));
        ammo--;cooldown=.12f;crossX=tx;crossY=ty;crossT=.18f;sounds.play(sShot,1,1,2,0,1);
    }

    private void reload(){if(mode==GAME&&caught<=0&&ammo<2){ammo=2;sounds.play(sReload,.9f,.9f,1,0,1);}}

    private void drawGame(Canvas c){
        drawMap(c);
        for(Enemy e:enemies)drawEnemy(c,e);
        for(Bullet b:bullets)drawBullet(c,b);
        drawPlayer(c);
        if(crossT>0)drawCross(c);
        if(caught>0)outlined(c,"CAUGHT!",px,py-Math.min(getWidth(),getHeight())*.16f,Math.min(getWidth(),getHeight())*.06f,Color.YELLOW,Paint.Align.CENTER);
        drawHud(c); drawTouch(c);
    }

    private void drawMap(Canvas c){
        int w=getWidth(),h=getHeight();reset();p.setColor(Color.rgb(151,184,72));c.drawRect(0,0,w,h,p);
        if(tiles!=null)c.drawBitmap(tiles,tile,new Rect(0,0,w,h),p);
        if(env!=null){
            float u=Math.min(w,h);Rect[] rs={leaves,bush,grass,leaves,bush,grass,leaves,rock};
            float[] xs={.05f,.16f,.30f,.50f,.68f,.83f,.95f,.73f},ys={.12f,.78f,.22f,.84f,.13f,.70f,.34f,.72f};
            for(int i=0;i<rs.length;i++){float z=u*(i%3==0?.27f:.16f),x=xs[i]*w,y=ys[i]*h;c.drawBitmap(env,rs[i],new RectF(x-z/2,y-z/2,x+z/2,y+z/2),p);}
        }
    }

    private void drawPlayer(Canvas c){
        float u=Math.min(getWidth(),getHeight()),w=u*.29f,h=w*116/206f;Rect src=((int)(walk*10)&1)==0?player1:player2;
        c.save();c.rotate(rotation,px,py);if(player!=null)c.drawBitmap(player,src,new RectF(px-w/2,py-h/2,px+w/2,py+h/2),p);c.restore();
    }

    private void drawEnemy(Canvas c,Enemy e){
        float u=Math.min(getWidth(),getHeight()),w=u*.17f,h=w*113/140f;Rect src=((int)e.phase&1)==0?enemy1:enemy2;
        float a=(float)Math.toDegrees(Math.atan2(py-e.y,px-e.x))+90;
        c.save();c.rotate(a,e.x,e.y);if(enemy!=null)c.drawBitmap(enemy,src,new RectF(e.x-w/2,e.y-h/2,e.x+w/2,e.y+h/2),p);c.restore();
    }

    private void drawBullet(Canvas c,Bullet b){
        float d=(float)Math.hypot(b.vx,b.vy),ux=b.vx/d,uy=b.vy/d;reset();p.setColor(Color.YELLOW);p.setStrokeWidth(7);p.setStrokeCap(Paint.Cap.ROUND);
        c.drawLine(b.x-ux*28,b.y-uy*28,b.x+ux*28,b.y+uy*28,p);
    }

    private void drawCross(Canvas c){
        float r=Math.min(getWidth(),getHeight())*.028f;reset();p.setStyle(Paint.Style.STROKE);p.setStrokeWidth(4);p.setColor(Color.WHITE);
        c.drawCircle(crossX,crossY,r,p);c.drawLine(crossX-r*1.4f,crossY,crossX+r*1.4f,crossY,p);c.drawLine(crossX,crossY-r*1.4f,crossX,crossY+r*1.4f,p);
    }

    private void drawHud(Canvas c){
        int w=getWidth(),h=getHeight();float u=Math.min(w,h);
        for(int i=0;i<5;i++){float r=u*.033f,cx=u*.052f+i*r*2.35f,cy=u*.052f;reset();p.setColor(i<lives?Color.rgb(224,54,48):Color.argb(70,255,255,255));c.drawCircle(cx,cy,r+4,p);drawFaceIcon(c,cx,cy,r);}
        outlined(c,String.format("%05d",score),w-u*.055f,u*.075f,u*.070f,Color.WHITE,Paint.Align.RIGHT);
        outlined(c,"REMAINING",w-u*.22f,h-u*.105f,u*.036f,Color.WHITE,Paint.Align.RIGHT);
        for(int i=0;i<2;i++){float x=w-u*(.18f-i*.055f),y=h-u*.065f;reset();p.setColor(i<ammo?Color.RED:Color.argb(80,255,255,255));c.drawRoundRect(new RectF(x-u*.012f,y-u*.036f,x+u*.012f,y+u*.036f),u*.008f,u*.008f,p);p.setColor(Color.rgb(248,211,65));c.drawRect(x-u*.012f,y+u*.017f,x+u*.012f,y+u*.036f,p);}
    }

    private void drawFaceIcon(Canvas c,float x,float y,float r){
        reset();p.setColor(Color.rgb(231,176,129));c.drawCircle(x,y,r*.9f,p);
        p.setColor(Color.rgb(134,91,39));c.drawOval(new RectF(x-r*.95f,y-r*.78f,x+r*.95f,y-r*.30f),p);
        p.setColor(Color.rgb(65,50,42));c.drawCircle(x-r*.27f,y-r*.05f,r*.08f,p);c.drawCircle(x+r*.27f,y-r*.05f,r*.08f,p);
        c.drawArc(new RectF(x-r*.52f,y-r*.02f,x+r*.52f,y+r*.72f),5,170,true,p);
    }

    private void drawTouch(Canvas c){
        float u=Math.min(getWidth(),getHeight());
        if(movePointer!=-1){float r=u*.105f;reset();p.setStyle(Paint.Style.STROKE);p.setStrokeWidth(u*.008f);p.setColor(Color.argb(95,255,255,255));c.drawCircle(baseX,baseY,r,p);p.setStyle(Paint.Style.FILL);c.drawCircle(stickX,stickY,r*.38f,p);}
        reset();p.setColor(Color.argb(95,20,20,20));c.drawOval(reload,p);outlined(c,"R",reload.centerX(),reload.centerY()+reload.height()*.16f,u*.055f,Color.WHITE,Paint.Align.CENTER);
    }

    private void drawOver(Canvas c){
        reset();p.setColor(Color.argb(205,0,0,0));c.drawRect(0,0,getWidth(),getHeight(),p);float u=Math.min(getWidth(),getHeight());
        outlined(c,"GAME OVER",getWidth()*.5f,getHeight()*.42f,u*.13f,Color.rgb(245,68,47),Paint.Align.CENTER);
        outlined(c,"SCORE "+score+"   RECORD "+record,getWidth()*.5f,getHeight()*.55f,u*.052f,Color.WHITE,Paint.Align.CENTER);
        outlined(c,"TAP TO PLAY AGAIN",getWidth()*.5f,getHeight()*.69f,u*.05f,Color.YELLOW,Paint.Align.CENTER);
    }

    private void outlined(Canvas c,String s,float x,float y,float size,int color,Paint.Align align){
        txt.setTextSize(size);txt.setTextAlign(align);txt.setStyle(Paint.Style.STROKE);txt.setStrokeWidth(Math.max(3,size*.1f));txt.setColor(Color.argb(190,20,20,20));c.drawText(s,x,y,txt);
        txt.setStyle(Paint.Style.FILL);txt.setColor(color);c.drawText(s,x,y,txt);
    }

    private boolean moveZone(float x,float y){return x<getWidth()*.30f&&y>getHeight()*.55f;}

    @Override public boolean onTouchEvent(MotionEvent e){
        int a=e.getActionMasked(),idx=e.getActionIndex();
        if(mode==MENU){if(a==MotionEvent.ACTION_UP&&(start.contains(e.getX(),e.getY())||e.getY()>getHeight()*.5f))startGame();return true;}
        if(mode==OVER){if(a==MotionEvent.ACTION_UP)startGame();return true;}
        if(a==MotionEvent.ACTION_DOWN||a==MotionEvent.ACTION_POINTER_DOWN){
            float x=e.getX(idx),y=e.getY(idx);int id=e.getPointerId(idx);
            if(reload.contains(x,y)){reload();return true;}
            Enemy hit=near(x,y,Math.min(getWidth(),getHeight())*.13f);
            if(hit!=null){fire(hit.x,hit.y);return true;}
            if(moveZone(x,y)&&movePointer==-1){movePointer=id;baseX=stickX=x;baseY=stickY=y;moveX=moveY=0;}
            else fire(x,y);
            return true;
        }
        if(a==MotionEvent.ACTION_MOVE&&movePointer!=-1){int i=e.findPointerIndex(movePointer);if(i>=0)stick(e.getX(i),e.getY(i));return true;}
        if(a==MotionEvent.ACTION_UP||a==MotionEvent.ACTION_POINTER_UP||a==MotionEvent.ACTION_CANCEL){
            int id=e.getPointerId(idx);if(id==movePointer||a==MotionEvent.ACTION_CANCEL){movePointer=-1;moveX=moveY=0;}return true;
        }
        return true;
    }

    private void stick(float x,float y){
        float r=Math.min(getWidth(),getHeight())*.105f,dx=x-baseX,dy=y-baseY,d=(float)Math.hypot(dx,dy);
        if(d>r&&d>0){dx=dx/d*r;dy=dy/d*r;}stickX=baseX+dx;stickY=baseY+dy;moveX=dx/r;moveY=dy/r;
    }

    private float clamp(float v,float lo,float hi){return Math.max(lo,Math.min(hi,v));}
}
