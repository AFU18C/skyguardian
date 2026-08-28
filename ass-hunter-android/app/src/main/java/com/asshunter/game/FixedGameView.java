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
import java.util.*;

public class FixedGameView extends View {
    private final Paint p = new Paint(Paint.ANTI_ALIAS_FLAG|Paint.FILTER_BITMAP_FLAG);
    private final Paint tp = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Random rnd = new Random();
    private final List<Enemy> enemies = new ArrayList<>();
    private final List<Bullet> bullets = new ArrayList<>();
    private final Bitmap face, menu, player, enemy, tiles, env, startArt;
    private final RectF start = new RectF(), reload = new RectF();
    private final Rect player1=new Rect(0,0,206,116), player2=new Rect(206,0,412,116);
    private final Rect enemy1=new Rect(420,339,560,452), enemy2=new Rect(560,339,700,452);
    private final Rect tile=new Rect(323,219,647,438), leaves=new Rect(1,1,168,159), bush=new Rect(170,1,283,113), rock=new Rect(285,31,332,77), grass=new Rect(170,115,231,167);
    private final SharedPreferences prefs;
    private final SoundPool sounds;
    private final int sndShot,sndReload,sndHit,sndCatch;

    private int mode=0, score=0, record, lives=5, ammo=2;
    private float px,py,rot=0,spawn=1.0f,cool=0,walk=0,caught=0;
    private float crossX,crossY,crossT;
    private int moveId=-1; private float baseX,baseY,stickX,stickY,mx,my;
    private long last=SystemClock.elapsedRealtime();

    private static class Enemy { float x,y,speed,phase; Enemy(float a,float b,float c){x=a;y=b;speed=c;} }
    private static class Bullet { float x,y,vx,vy; Bullet(float a,float b,float c,float d){x=a;y=b;vx=c;vy=d;} }

    public FixedGameView(Context c){
        super(c); setFocusable(true); p.setTypeface(Typeface.DEFAULT_BOLD); tp.setTypeface(Typeface.DEFAULT_BOLD);
        face=decode(EmbeddedImages.portraitBase64());
        menu=BitmapFactory.decodeResource(getResources(),R.drawable.main_menu);
        player=BitmapFactory.decodeResource(getResources(),R.drawable.player);
        enemy=BitmapFactory.decodeResource(getResources(),R.drawable.enemy);
        tiles=BitmapFactory.decodeResource(getResources(),R.drawable.tiles);
        env=BitmapFactory.decodeResource(getResources(),R.drawable.environment);
        startArt=BitmapFactory.decodeResource(getResources(),R.drawable.start_button);
        prefs=c.getSharedPreferences("ass_hunter_hd",Context.MODE_PRIVATE); record=prefs.getInt("record",0);
        AudioAttributes a=new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_GAME).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build();
        sounds=new SoundPool.Builder().setMaxStreams(4).setAudioAttributes(a).build();
        sndShot=sounds.load(c,R.raw.shot,1); sndReload=sounds.load(c,R.raw.reload,1); sndHit=sounds.load(c,R.raw.hit,1); sndCatch=sounds.load(c,R.raw.catch_sound,1);
    }

    private Bitmap decode(String s){try{byte[] d=Base64.decode(s,Base64.DEFAULT);return BitmapFactory.decodeByteArray(d,0,d.length);}catch(Exception e){return null;}}
    private void reset(){p.setAlpha(255);p.setShader(null);p.setStyle(Paint.Style.FILL);p.setColor(Color.WHITE);p.setStrokeWidth(1);}

    @Override protected void onSizeChanged(int w,int h,int ow,int oh){
        px=w*.5f; py=h*.52f; float bw=Math.min(w*.25f,360),bh=Math.min(h*.15f,105); start.set(w*.73f-bw/2,h*.72f,w*.73f+bw/2,h*.72f+bh);
        float r=Math.min(w,h)*.065f; reload.set(w-r*2.35f,h-r*2.2f,w-r*.35f,h-r*.2f);
    }

    @Override protected void onDraw(Canvas c){
        long n=SystemClock.elapsedRealtime(); float dt=Math.min(.033f,Math.max(0,(n-last)/1000f)); last=n; reset();
        if(mode==0) drawMenu(c); else {if(mode==1) update(dt); drawGame(c); if(mode==2) gameOver(c);} postInvalidateOnAnimation();
    }

    private void drawMenu(Canvas c){
        int w=getWidth(),h=getHeight(); reset();
        p.setShader(new LinearGradient(0,0,0,h,Color.rgb(229,235,214),Color.rgb(159,190,126),Shader.TileMode.CLAMP)); c.drawRect(0,0,w,h,p); reset();
        if(menu!=null)c.drawBitmap(menu,new Rect(0,145,menu.getWidth(),menu.getHeight()),new Rect(0,0,w,h),p);
        drawFace(c,w*.377f,h*.653f,w*.430f,h*.790f);
        text(c,"RECORD: "+record,w*.74f,h*.60f,Math.min(w,h)*.06f,Color.rgb(255,218,42),Paint.Align.CENTER);
        reset(); if(startArt!=null)c.drawBitmap(startArt,null,start,p); text(c,"START GAME",start.centerX(),start.centerY()+start.height()*.16f,Math.min(w,h)*.07f,Color.WHITE,Paint.Align.CENTER);
    }

    private void drawFace(Canvas c,float l,float t,float r,float b){
        if(face==null)return; RectF d=new RectF(l,t,r,b); Path path=new Path(); path.addOval(d,Path.Direction.CW); reset(); p.setColor(Color.rgb(188,132,90)); c.drawOval(d,p);
        c.save(); c.clipPath(path); reset(); int w=face.getWidth(),h=face.getHeight(); Rect src=new Rect((int)(w*.20f),(int)(h*.30f),(int)(w*.80f),(int)(h*.73f)); c.drawBitmap(face,src,d,p); c.restore(); reset();
    }

    private void startGame(){mode=1;score=0;lives=5;ammo=2;enemies.clear();bullets.clear();px=getWidth()*.5f;py=getHeight()*.52f;rot=0;spawn=1.0f;cool=0;caught=0;moveId=-1;mx=my=0;crossT=0;last=SystemClock.elapsedRealtime();}

    private void update(float dt){
        crossT=Math.max(0,crossT-dt); if(caught>0){caught+=dt;if(caught>1.1f){caught=0;enemies.clear();bullets.clear();lives--;spawn=1.3f;if(lives<=0)mode=2;}return;}
        cool=Math.max(0,cool-dt);walk+=dt;px+=mx*330*dt;py+=my*330*dt;float ex=getWidth()*.07f,ey=getHeight()*.11f;px=clamp(px,ex,getWidth()-ex);py=clamp(py,ey,getHeight()-ey);
        spawn-=dt;if(spawn<=0&&enemies.size()<4){spawnEnemy();spawn=1.1f+rnd.nextFloat()*.55f;}
        Iterator<Bullet> bi=bullets.iterator();while(bi.hasNext()){Bullet b=bi.next();b.x+=b.vx*dt;b.y+=b.vy*dt;if(b.x<-100||b.y<-100||b.x>getWidth()+100||b.y>getHeight()+100)bi.remove();}
        for(Enemy e:enemies){float dx=px-e.x,dy=py-e.y,d=(float)Math.hypot(dx,dy);if(d<Math.min(getWidth(),getHeight())*.07f){caught=.001f;mx=my=0;sounds.play(sndCatch,.8f,.8f,1,0,1);break;}if(d>1){e.x+=dx/d*e.speed*dt;e.y+=dy/d*e.speed*dt;}e.phase+=dt*6.5f;}
        if(caught==0){float hr=Math.min(getWidth(),getHeight())*.12f;outer:for(int i=bullets.size()-1;i>=0;i--){Bullet b=bullets.get(i);for(int j=enemies.size()-1;j>=0;j--){Enemy e=enemies.get(j);float dx=b.x-e.x,dy=b.y-e.y;if(dx*dx+dy*dy<hr*hr){bullets.remove(i);enemies.remove(j);score+=100;if(score>record){record=score;prefs.edit().putInt("record",record).apply();}sounds.play(sndHit,.75f,.75f,1,0,1);continue outer;}}}}
    }

    private void spawnEnemy(){int s=rnd.nextInt(4);float x,y,pad=85;if(s==0){x=-pad;y=getHeight()*(.16f+rnd.nextFloat()*.68f);}else if(s==1){x=getWidth()+pad;y=getHeight()*(.16f+rnd.nextFloat()*.68f);}else if(s==2){x=getWidth()*(.12f+rnd.nextFloat()*.76f);y=-pad;}else{x=getWidth()*(.12f+rnd.nextFloat()*.76f);y=getHeight()+pad;}enemies.add(new Enemy(x,y,58+rnd.nextFloat()*26));}

    private Enemy near(float x,float y,float radius){Enemy best=null;float bd=radius*radius;for(Enemy e:enemies){float dx=e.x-x,dy=e.y-y,d=dx*dx+dy*dy;if(d<bd){bd=d;best=e;}}return best;}

    private void fire(float tx,float ty){
        if(mode!=1||caught>0||cool>0)return;if(ammo<=0){reload();return;}
        Enemy a=near(tx,ty,Math.min(getWidth(),getHeight())*.18f);if(a!=null){tx=a.x;ty=a.y;}
        float dx=tx-px,dy=ty-py,d=(float)Math.hypot(dx,dy);if(d<2)return;float ux=dx/d,uy=dy/d;rot=(float)Math.toDegrees(Math.atan2(dy,dx))-180f;
        bullets.add(new Bullet(px+ux*58,py+uy*58,ux*1550,uy*1550));ammo--;cool=.14f;crossX=tx;crossY=ty;crossT=.22f;sounds.play(sndShot,1,1,2,0,1);
    }
    private void reload(){if(mode==1&&caught==0&&ammo<2){ammo=2;sounds.play(sndReload,.9f,.9f,1,0,1);}}

    private void drawGame(Canvas c){drawMap(c);for(Enemy e:enemies)drawEnemy(c,e);for(Bullet b:bullets)drawBullet(c,b);drawPlayer(c);if(crossT>0)cross(c);if(caught>0)caught(c);hud(c);touchUi(c);}
    private void drawMap(Canvas c){int w=getWidth(),h=getHeight();reset();p.setColor(Color.rgb(151,184,72));c.drawRect(0,0,w,h,p);if(tiles!=null)c.drawBitmap(tiles,tile,new Rect(0,0,w,h),p);if(env!=null){float u=Math.min(w,h);Rect[] ss={leaves,bush,grass,leaves,bush,grass,leaves};float[] xs={.06f,.16f,.30f,.52f,.70f,.86f,.95f},ys={.12f,.78f,.24f,.84f,.15f,.72f,.35f};for(int i=0;i<7;i++){float z=u*(i%3==0?.28f:.18f),x=xs[i]*w,y=ys[i]*h;c.drawBitmap(env,ss[i],new RectF(x-z/2,y-z/2,x+z/2,y+z/2),p);}float z=u*.09f;c.drawBitmap(env,rock,new RectF(w*.36f-z,h*.25f-z,w*.36f+z,h*.25f+z),p);}}
    private void drawPlayer(Canvas c){reset();float u=Math.min(getWidth(),getHeight()),w=u*.29f,h=w*116/206;Rect src=((int)(walk*7)&1)==0?player1:player2;c.save();c.rotate(rot,px,py);if(player!=null)c.drawBitmap(player,src,new RectF(px-w/2,py-h/2,px+w/2,py+h/2),p);c.restore();}
    private void drawEnemy(Canvas c,Enemy e){reset();float u=Math.min(getWidth(),getHeight()),w=u*.17f,h=w*113/140;Rect src=((int)e.phase&1)==0?enemy1:enemy2;float a=(float)Math.toDegrees(Math.atan2(py-e.y,px-e.x))+90;c.save();c.rotate(a,e.x,e.y);if(enemy!=null)c.drawBitmap(enemy,src,new RectF(e.x-w/2,e.y-h/2,e.x+w/2,e.y+h/2),p);c.restore();}
    private void drawBullet(Canvas c,Bullet b){reset();float d=(float)Math.hypot(b.vx,b.vy),ux=b.vx/d,uy=b.vy/d;p.setColor(Color.YELLOW);p.setStrokeWidth(7);p.setStrokeCap(Paint.Cap.ROUND);c.drawLine(b.x-ux*28,b.y-uy*28,b.x+ux*28,b.y+uy*28,p);}
    private void cross(Canvas c){reset();float r=Math.min(getWidth(),getHeight())*.03f;p.setStyle(Paint.Style.STROKE);p.setStrokeWidth(4);p.setColor(Color.WHITE);c.drawCircle(crossX,crossY,r,p);c.drawLine(crossX-r*1.4f,crossY,crossX+r*1.4f,crossY,p);c.drawLine(crossX,crossY-r*1.4f,crossX,crossY+r*1.4f,p);}
    private void caught(Canvas c){float b=(float)Math.abs(Math.sin(caught*18)),u=Math.min(getWidth(),getHeight()),w=u*.22f,h=w*113/140,y=py-18-b*24;reset();if(enemy!=null)c.drawBitmap(enemy,enemy1,new RectF(px-w/2,y-h/2,px+w/2,y+h/2),p);text(c,"CAUGHT!",px,py-w*.75f,u*.065f,Color.YELLOW,Paint.Align.CENTER);}

    private void hud(Canvas c){reset();int w=getWidth(),h=getHeight();float u=Math.min(w,h);for(int i=0;i<5;i++){float r=u*.034f,cx=u*.052f+i*r*2.35f,cy=u*.052f;p.setColor(i<lives?Color.rgb(224,54,48):Color.argb(70,255,255,255));c.drawCircle(cx,cy,r+4,p);faceCircle(c,cx,cy,r);}text(c,String.format("%05d",score),w-u*.055f,u*.075f,u*.07f,Color.WHITE,Paint.Align.RIGHT);text(c,"REMAINING",w-u*.22f,h-u*.105f,u*.036f,Color.WHITE,Paint.Align.RIGHT);for(int i=0;i<2;i++){float x=w-u*(.18f-i*.055f),y=h-u*.065f;p.setColor(i<ammo?Color.RED:Color.argb(80,255,255,255));c.drawRoundRect(new RectF(x-u*.012f,y-u*.036f,x+u*.012f,y+u*.036f),u*.008f,u*.008f,p);p.setColor(Color.rgb(248,211,65));c.drawRect(x-u*.012f,y+u*.017f,x+u*.012f,y+u*.036f,p);}}
    private void faceCircle(Canvas c,float x,float y,float r){if(face==null)return;Path q=new Path();q.addCircle(x,y,r,Path.Direction.CW);c.save();c.clipPath(q);int w=face.getWidth(),h=face.getHeight();c.drawBitmap(face,new Rect((int)(w*.16f),(int)(h*.14f),(int)(w*.84f),(int)(h*.76f)),new RectF(x-r,y-r,x+r,y+r),p);c.restore();}
    private void touchUi(Canvas c){reset();float u=Math.min(getWidth(),getHeight());if(moveId!=-1){float r=u*.105f;p.setStyle(Paint.Style.STROKE);p.setStrokeWidth(u*.008f);p.setColor(Color.argb(90,255,255,255));c.drawCircle(baseX,baseY,r,p);p.setStyle(Paint.Style.FILL);c.drawCircle(stickX,stickY,r*.38f,p);}p.setColor(Color.argb(90,20,20,20));c.drawOval(reload,p);text(c,"R",reload.centerX(),reload.centerY()+reload.height()*.16f,u*.055f,Color.WHITE,Paint.Align.CENTER);}
    private void gameOver(Canvas c){reset();p.setColor(Color.argb(205,0,0,0));c.drawRect(0,0,getWidth(),getHeight(),p);float u=Math.min(getWidth(),getHeight());text(c,"GAME OVER",getWidth()*.5f,getHeight()*.42f,u*.13f,Color.rgb(245,68,47),Paint.Align.CENTER);text(c,"SCORE "+score+"   RECORD "+record,getWidth()*.5f,getHeight()*.55f,u*.052f,Color.WHITE,Paint.Align.CENTER);text(c,"TAP TO PLAY AGAIN",getWidth()*.5f,getHeight()*.69f,u*.05f,Color.YELLOW,Paint.Align.CENTER);}
    private void text(Canvas c,String s,float x,float y,float z,int color,Paint.Align align){tp.setTextSize(z);tp.setTextAlign(align);tp.setStyle(Paint.Style.STROKE);tp.setStrokeWidth(Math.max(3,z*.1f));tp.setColor(Color.argb(190,20,20,20));c.drawText(s,x,y,tp);tp.setStyle(Paint.Style.FILL);tp.setColor(color);c.drawText(s,x,y,tp);}

    private boolean moveZone(float x,float y){return x<getWidth()*.28f&&y>getHeight()*.60f;}
    @Override public boolean onTouchEvent(MotionEvent e){int a=e.getActionMasked(),i=e.getActionIndex();if(mode==0){if(a==MotionEvent.ACTION_UP&&start.contains(e.getX(),e.getY()))startGame();return true;}if(mode==2){if(a==MotionEvent.ACTION_UP)startGame();return true;}if(a==MotionEvent.ACTION_DOWN||a==MotionEvent.ACTION_POINTER_DOWN){float x=e.getX(i),y=e.getY(i);int id=e.getPointerId(i);if(reload.contains(x,y)){reload();return true;}Enemy hit=near(x,y,Math.min(getWidth(),getHeight())*.14f);if(hit!=null){fire(hit.x,hit.y);return true;}if(moveZone(x,y)&&moveId==-1){moveId=id;baseX=stickX=x;baseY=stickY=y;mx=my=0;}else fire(x,y);return true;}if(a==MotionEvent.ACTION_MOVE&&moveId!=-1){int j=e.findPointerIndex(moveId);if(j>=0)stick(e.getX(j),e.getY(j));return true;}if(a==MotionEvent.ACTION_UP||a==MotionEvent.ACTION_POINTER_UP||a==MotionEvent.ACTION_CANCEL){int id=e.getPointerId(i);if(id==moveId||a==MotionEvent.ACTION_CANCEL){moveId=-1;mx=my=0;}return true;}return true;}
    private void stick(float x,float y){float r=Math.min(getWidth(),getHeight())*.105f,dx=x-baseX,dy=y-baseY,d=(float)Math.hypot(dx,dy);if(d>r){dx=dx/d*r;dy=dy/d*r;}stickX=baseX+dx;stickY=baseY+dy;mx=dx/r;my=dy/r;}
    private float clamp(float v,float lo,float hi){return Math.max(lo,Math.min(hi,v));}
}
