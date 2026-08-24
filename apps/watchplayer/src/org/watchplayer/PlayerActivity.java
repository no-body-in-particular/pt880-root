package org.watchplayer;

import android.app.Activity;
import android.bluetooth.BluetoothDevice;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.ServiceConnection;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.media.AudioManager;
import android.os.Bundle;
import android.os.Handler;
import android.os.IBinder;
import android.text.TextUtils;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.List;

/**
 * 240x240, no touchscreen, one or two hardware buttons.
 *
 * Button A is the main key (DPAD_CENTER / ENTER). Button B is whatever the
 * second key reports; the app watches for it and unlocks two-button mode the
 * first time it sees one. Until then everything is reachable from button A
 * alone using double- and long-press.
 */
public class PlayerActivity extends Activity
        implements MusicService.Listener, BtHelper.Listener {

    private static final int LONG_MS = 600;
    private static final int DOUBLE_MS = 280;

    private static final int SCREEN_NOW = 0;
    private static final int SCREEN_MENU = 1;
    private static final int SCREEN_BT = 2;

    private static final int A_NONE = 0, A_SHORT = 1, A_DOUBLE = 2, A_LONG = 3, A_XLONG = 4;

    /** Auto-repeats seen before a hold counts as the "extra long" gesture. */
    private static final int XLONG_REPEATS = 60;

    private MusicService svc;
    private BtHelper bt;
    private AudioManager audio;
    private SharedPreferences prefs;

    private final Handler ui = new Handler();

    private int screen = SCREEN_NOW;
    private int sel = 0;
    private boolean twoButtons = false;
    private boolean keepAwake = false;
    private String btStatus = "";

    // views
    private TextView vStatus, vTitle, vSub, vVol, vHint;
    private LinearLayout vList, volBarRow;
    private View volFill, volRest;
    private ScrollView vScroll;
    private View nowPane, listPane;

    // key state
    private int downKey = -1;
    private boolean longFired = false;
    private int holdRepeats = 0;
    private int lastVol = -1;
    private long volFlashUntil = 0;
    private long lastShortAt = 0;
    private int lastShortKey = -1;
    private Runnable longTask, singleTask;

    // ---------------------------------------------------------------- lifecycle

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        prefs = getSharedPreferences("watchplayer", MODE_PRIVATE);
        twoButtons = prefs.getBoolean("twoButtons", false);
        keepAwake = prefs.getBoolean("keepAwake", false);

        audio = (AudioManager) getSystemService(Context.AUDIO_SERVICE);
        setVolumeControlStream(AudioManager.STREAM_MUSIC);

        buildUi();
        applyKeepAwake();

        bt = new BtHelper(this, this);

        Intent i = new Intent(this, MusicService.class);
        startService(i);
        bindService(i, conn, BIND_AUTO_CREATE);
    }

    @Override
    protected void onStart() {
        super.onStart();
        bt.start();
        ui.post(tick);

        // "-e pair <MAC>" pairs a known address without needing it to show up
        // in a scan first; the buds only advertise while in pairing mode.
        String addr = getIntent().getStringExtra("pair");
        if (addr != null && addr.length() > 0) {
            getIntent().removeExtra("pair");
            screen = SCREEN_BT;
            sel = 0;
            bt.pairAddress(addr);
            render();
        }
    }

    @Override
    protected void onStop() {
        super.onStop();
        ui.removeCallbacks(tick);
        bt.cancelScan();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        bt.stop();
        if (svc != null) svc.setListener(null);
        try { unbindService(conn); } catch (Exception e) { /* not bound */ }
    }

    private final ServiceConnection conn = new ServiceConnection() {
        public void onServiceConnected(ComponentName n, IBinder binder) {
            svc = ((MusicService.LocalBinder) binder).get();
            svc.setListener(PlayerActivity.this);
            render();
        }
        public void onServiceDisconnected(ComponentName n) { svc = null; }
    };

    // ---------------------------------------------------------------- ui build

    private TextView text(int px, int colour, boolean bold) {
        TextView t = new TextView(this);
        t.setTextSize(TypedValue.COMPLEX_UNIT_PX, px);
        t.setTextColor(colour);
        t.setGravity(Gravity.CENTER_HORIZONTAL);
        if (bold) t.setTypeface(t.getTypeface(), android.graphics.Typeface.BOLD);
        return t;
    }

    private void buildUi() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.BLACK);
        root.setPadding(6, 6, 6, 4);

        // --- now playing pane
        LinearLayout now = new LinearLayout(this);
        now.setOrientation(LinearLayout.VERTICAL);
        now.setGravity(Gravity.CENTER_VERTICAL);

        vStatus = text(11, 0xFF7FB3FF, false);
        vTitle = text(20, Color.WHITE, true);
        vTitle.setMaxLines(3);
        vTitle.setEllipsize(TextUtils.TruncateAt.END);
        vSub = text(13, 0xFFBBBBBB, false);
        vVol = text(11, 0xFF666666, false);

        // Drawn as views, not block characters: this build's font has no
        // U+25AE/U+25AF, so a text bar renders as blank.
        volFill = new View(this);
        volRest = new View(this);
        volBarRow = new LinearLayout(this);
        volBarRow.setOrientation(LinearLayout.HORIZONTAL);
        volBarRow.addView(volFill, new LinearLayout.LayoutParams(0, 4, 1));
        volBarRow.addView(volRest, new LinearLayout.LayoutParams(0, 4, 1));
        LinearLayout volWrap = new LinearLayout(this);
        volWrap.setOrientation(LinearLayout.VERTICAL);
        volWrap.setPadding(34, 0, 34, 0);
        volWrap.addView(volBarRow,
                new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 4));

        now.addView(vStatus, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 0));
        now.addView(spacer(8));
        now.addView(vTitle, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 0));
        now.addView(spacer(6));
        now.addView(vSub, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 0));
        now.addView(spacer(9));
        now.addView(volWrap, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 0));
        now.addView(spacer(3));
        now.addView(vVol, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 0));
        nowPane = now;

        // --- list pane (menu + bluetooth)
        vScroll = new ScrollView(this);
        vList = new LinearLayout(this);
        vList.setOrientation(LinearLayout.VERTICAL);
        vScroll.addView(vList);
        listPane = vScroll;

        vHint = text(10, 0xFF777777, false);

        LinearLayout body = new LinearLayout(this);
        body.setOrientation(LinearLayout.VERTICAL);
        body.addView(nowPane, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1));
        body.addView(listPane, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1));

        root.addView(body, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1));
        root.addView(vHint, lp(ViewGroup.LayoutParams.MATCH_PARENT, 0, 0));

        setContentView(root);
    }

    private LinearLayout.LayoutParams lp(int w, int h, float weight) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(w, h == 0
                ? ViewGroup.LayoutParams.WRAP_CONTENT : h);
        if (weight > 0) { p.height = 0; p.weight = weight; }
        return p;
    }

    private View spacer(int px) {
        View v = new View(this);
        v.setLayoutParams(new LinearLayout.LayoutParams(1, px));
        return v;
    }

    private void applyKeepAwake() {
        if (keepAwake) getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        else getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    }

    // ---------------------------------------------------------------- rendering

    private final Runnable tick = new Runnable() {
        public void run() {
            // Catches volume moved by anyone else (AVRCP absolute volume, the
            // system, another app) so the bar still reflects reality.
            int v = audio.getStreamVolume(AudioManager.STREAM_MUSIC);
            if (lastVol >= 0 && v != lastVol) volFlashUntil = System.currentTimeMillis() + 2500;
            lastVol = v;

            if (screen == SCREEN_NOW) render();
            ui.postDelayed(this, 1000);
        }
    };

    public void onPlayerChanged() { render(); }

    public void onBtChanged(String status) {
        btStatus = status;
        render();
    }

    private void render() {
        boolean list = (screen != SCREEN_NOW);
        nowPane.setVisibility(list ? View.GONE : View.VISIBLE);
        listPane.setVisibility(list ? View.VISIBLE : View.GONE);

        if (screen == SCREEN_NOW) renderNow();
        else if (screen == SCREEN_MENU) renderList(menuItems(), "Menu");
        else renderList(btItems(), "Bluetooth");

        vHint.setText(hint());
    }

    private void renderNow() {
        if (svc == null) return;
        String head = (btStatus.length() > 0) ? btStatus : "";
        BluetoothDevice a = (bt != null) ? bt.connectedAudio() : null;
        if (a != null) {
            String n = null;
            try { n = a.getName(); } catch (Exception e) { /* ignore */ }
            head = (n == null ? "Headphones" : n) + " ✓";
        }
        vStatus.setText(head);

        vTitle.setText(svc.title());

        int n = svc.tracks().size();
        StringBuilder sb = new StringBuilder();
        if (n > 0) {
            sb.append(svc.isPlaying() ? "▶ " : "‖ ");
            sb.append(svc.index() + 1).append(" / ").append(n);
            int d = svc.duration();
            if (d > 0) sb.append("   ").append(mmss(svc.position())).append(" / ").append(mmss(d));
        } else {
            sb.append("Put files in /sdcard/Music");
        }
        if (svc.note().length() > 0) sb.append("\n").append(svc.note());
        vSub.setText(sb.toString());

        renderVolume();
    }

    /** Compact volume readout; the stock volume panel is unusable at 240px. */
    private void renderVolume() {
        int max = audio.getStreamMaxVolume(AudioManager.STREAM_MUSIC);
        int cur = audio.getStreamVolume(AudioManager.STREAM_MUSIC);
        if (max <= 0) return;

        boolean flash = System.currentTimeMillis() < volFlashUntil;
        ((LinearLayout.LayoutParams) volFill.getLayoutParams()).weight = cur;
        ((LinearLayout.LayoutParams) volRest.getLayoutParams()).weight = Math.max(0, max - cur);
        volFill.setBackgroundColor(flash ? 0xFF7FB3FF : 0xFF5A5A5A);
        volRest.setBackgroundColor(0xFF262626);
        volBarRow.requestLayout();

        vVol.setText("vol " + cur + "/" + max);
        vVol.setTextColor(flash ? 0xFF7FB3FF : 0xFF666666);
    }

    private static String mmss(int ms) {
        int s = ms / 1000;
        return (s / 60) + ":" + (s % 60 < 10 ? "0" : "") + (s % 60);
    }

    private void renderList(List<String> items, String title) {
        vList.removeAllViews();
        TextView h = text(12, 0xFF7FB3FF, true);
        h.setText(title);
        h.setPadding(0, 0, 0, 4);
        vList.addView(h);

        if (sel >= items.size()) sel = Math.max(0, items.size() - 1);

        View selectedView = null;
        for (int i = 0; i < items.size(); i++) {
            TextView t = text(14, i == sel ? Color.BLACK : Color.WHITE, i == sel);
            t.setText(items.get(i));
            t.setGravity(Gravity.LEFT);
            t.setPadding(5, 4, 5, 4);
            t.setBackgroundColor(i == sel ? 0xFF7FB3FF : Color.TRANSPARENT);
            vList.addView(t, new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            if (i == sel) selectedView = t;
        }
        final View target = selectedView;
        if (target != null) {
            vScroll.post(new Runnable() {
                public void run() {
                    int y = target.getTop() - (vScroll.getHeight() / 2) + (target.getHeight() / 2);
                    vScroll.smoothScrollTo(0, Math.max(0, y));
                }
            });
        }
    }

    private String hint() {
        if (twoButtons) {
            if (screen == SCREEN_NOW) return "A:play  hold:menu  B:next  B hold:prev";
            return "A:pick  hold:back  B:down  B hold:up";
        }
        if (screen == SCREEN_NOW) return "tap:play   hold:menu";
        return "tap:move  hold:pick  hold2s:back";
    }

    // ---------------------------------------------------------------- menus

    private List<String> menuItems() {
        List<String> l = new ArrayList<String>();
        l.add((svc != null && svc.isPlaying()) ? "Pause" : "Play");
        l.add("Next track");
        l.add("Previous track");
        l.add("Volume up");
        l.add("Volume down");
        l.add("Bluetooth");
        l.add("Rescan music");
        l.add(keepAwake ? "Screen: always on" : "Screen: normal");
        l.add("Back");
        l.add("Exit app");
        return l;
    }

    private void doMenu(int i) {
        switch (i) {
            case 0: if (svc != null) svc.toggle(); break;
            case 1: if (svc != null) svc.next(); break;
            case 2: if (svc != null) svc.prev(); break;
            case 3: audio.adjustStreamVolume(AudioManager.STREAM_MUSIC, AudioManager.ADJUST_RAISE, 0); break;
            case 4: audio.adjustStreamVolume(AudioManager.STREAM_MUSIC, AudioManager.ADJUST_LOWER, 0); break;
            case 5: screen = SCREEN_BT; sel = 0; bt.scan(); break;
            case 6: if (svc != null) svc.rescan(); break;
            case 7:
                keepAwake = !keepAwake;
                prefs.edit().putBoolean("keepAwake", keepAwake).commit();
                applyKeepAwake();
                break;
            case 8: screen = SCREEN_NOW; sel = 0; break;
            default:
                // A real quit: stop the music too, or it keeps playing with no
                // UI left to stop it from.
                if (svc != null) svc.pause();
                stopService(new Intent(this, MusicService.class));
                finish();
                return;
        }
        render();
    }

    private List<String> btItems() {
        List<String> l = new ArrayList<String>();
        l.add("Scan again");
        List<BtHelper.Dev> devs = bt.devices();
        for (int i = 0; i < devs.size(); i++) {
            BtHelper.Dev d = devs.get(i);
            l.add((d.bonded ? "* " : "") + d.name);
        }
        l.add("Back");
        return l;
    }

    private void doBt(int i) {
        List<BtHelper.Dev> devs = bt.devices();
        if (i == 0) { bt.scan(); render(); return; }
        int di = i - 1;
        if (di >= 0 && di < devs.size()) {
            bt.pairAndConnect(devs.get(di).device);
            render();
            return;
        }
        screen = SCREEN_MENU;
        sel = 0;
        render();
    }

    // ---------------------------------------------------------------- keys

    private boolean isButtonA(int k) {
        return k == KeyEvent.KEYCODE_DPAD_CENTER || k == KeyEvent.KEYCODE_ENTER;
    }

    /** The watch's second key. Deliberately not the volume keys: those belong
     *  to the headphones' own volume buttons over AVRCP. */
    private boolean isButtonB(int k) {
        switch (k) {
            case KeyEvent.KEYCODE_MENU:
            case KeyEvent.KEYCODE_CAMERA:
            case KeyEvent.KEYCODE_DPAD_DOWN:
            case KeyEvent.KEYCODE_DPAD_RIGHT:
                return true;
            default:
                return false;
        }
    }

    private boolean isVolumeKey(int k) {
        return k == KeyEvent.KEYCODE_VOLUME_UP || k == KeyEvent.KEYCODE_VOLUME_DOWN;
    }

    /** Nudge STREAM_MUSIC and flash the on-screen bar. Flag 0 suppresses the
     *  stock volume panel, which does not fit a 240px screen. */
    private void bumpVolume(int keyCode) {
        audio.adjustStreamVolume(AudioManager.STREAM_MUSIC,
                keyCode == KeyEvent.KEYCODE_VOLUME_UP
                        ? AudioManager.ADJUST_RAISE : AudioManager.ADJUST_LOWER, 0);
        lastVol = audio.getStreamVolume(AudioManager.STREAM_MUSIC);
        volFlashUntil = System.currentTimeMillis() + 2500;
        render();
    }

    /*
     * This firmware does not hand the main key to apps the normal way. A tap is
     * swallowed entirely and re-emitted as a synthetic BACK (deviceId=-1); a
     * hold leaks DPAD_CENTER auto-repeats first and then still ends in BACK.
     *
     * So BACK is the authoritative "key released" signal, and whether repeats
     * were seen beforehand is what separates a hold from a tap.
     */
    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        // Volume from the headphones (AVRCP) or anywhere else, on every screen.
        // Repeats are honoured so holding the bud button ramps.
        if (isVolumeKey(keyCode)) { bumpVolume(keyCode); return true; }

        if (keyCode == KeyEvent.KEYCODE_BACK) return true;      // acted on in onKeyUp

        if (isButtonA(keyCode)) {
            // Auto-repeats are the only part of the hold the app is allowed to
            // see, so their count is the length of the press.
            if (event.getRepeatCount() > holdRepeats) holdRepeats = event.getRepeatCount();
            return true;
        }
        if (!isButtonB(keyCode)) return super.onKeyDown(keyCode, event);
        if (event.getRepeatCount() > 0) return true;

        downKey = keyCode;
        longFired = false;
        cancel(longTask);
        final int k = keyCode;
        longTask = new Runnable() {
            public void run() {
                longFired = true;
                cancel(singleTask);
                act(k, A_LONG);
            }
        };
        ui.postDelayed(longTask, LONG_MS);
        return true;
    }

    @Override
    public boolean onKeyUp(int keyCode, KeyEvent event) {
        if (isVolumeKey(keyCode)) return true;                  // handled on the way down

        if (keyCode == KeyEvent.KEYCODE_BACK) {
            int reps = holdRepeats;
            holdRepeats = 0;
            int kind = (reps == 0) ? A_SHORT : (reps < XLONG_REPEATS ? A_LONG : A_XLONG);
            act(KeyEvent.KEYCODE_DPAD_CENTER, kind);
            return true;
        }
        if (isButtonA(keyCode)) return true;                    // BACK is authoritative

        if (!isButtonB(keyCode)) return super.onKeyUp(keyCode, event);
        cancel(longTask);
        if (longFired || downKey != keyCode) { downKey = -1; return true; }
        downKey = -1;

        if (!twoButtons) {                           // a real second key exists
            twoButtons = true;
            prefs.edit().putBoolean("twoButtons", true).commit();
        }
        act(keyCode, A_SHORT);
        return true;
    }


    private void cancel(Runnable r) { if (r != null) ui.removeCallbacks(r); }

    private void act(int key, int kind) {
        boolean a = isButtonA(key);

        if (screen == SCREEN_NOW) {
            if (a) {
                // Any hold opens the menu, however long. People hold a watch
                // button for several seconds, and making a longer hold mean
                // something else just loses them the menu.
                if (kind == A_SHORT) { if (svc != null) svc.toggle(); }
                else { screen = SCREEN_MENU; sel = 0; }
            } else {
                if (kind == A_SHORT || kind == A_DOUBLE) { if (svc != null) svc.next(); }
                else if (kind == A_LONG) { if (svc != null) svc.prev(); }
            }
        } else {
            List<String> items = (screen == SCREEN_MENU) ? menuItems() : btItems();
            if (a) {
                if (twoButtons) {
                    // B does the moving, so A can commit outright.
                    if (kind == A_SHORT) {
                        if (screen == SCREEN_MENU) doMenu(sel); else doBt(sel);
                        return;
                    }
                    if (kind == A_LONG || kind == A_XLONG) leaveList();
                } else {
                    // One button: the tap steps the highlight and the hold
                    // commits it. No double-tap, which this firmware eats.
                    if (kind == A_SHORT) sel = (sel + 1) % items.size();
                    else if (kind == A_LONG) {
                        if (screen == SCREEN_MENU) doMenu(sel); else doBt(sel);
                        return;
                    } else if (kind == A_XLONG) leaveList();
                }
            } else {
                if (kind == A_SHORT || kind == A_DOUBLE) sel = (sel + 1) % items.size();
                else if (kind == A_LONG) sel = (sel - 1 + items.size()) % items.size();
            }
        }
        render();
    }

    /** Step out of the menu or the Bluetooth list by one level. */
    private void leaveList() {
        if (screen == SCREEN_BT) { screen = SCREEN_MENU; sel = 0; bt.cancelScan(); }
        else { screen = SCREEN_NOW; sel = 0; }
    }

    /** Long-press of the main key arrives here as BACK. From the now-playing
     *  screen that opens the menu; anywhere else it steps back one level.
     *  Leaving the app is deliberate only, via the Exit item. */
    private void goBack() {
        if (screen == SCREEN_NOW) { screen = SCREEN_MENU; sel = 0; }
        else if (screen == SCREEN_BT) { screen = SCREEN_MENU; sel = 0; bt.cancelScan(); }
        else { screen = SCREEN_NOW; sel = 0; }
        render();
    }

    @Override
    public void onBackPressed() { goBack(); }
}
