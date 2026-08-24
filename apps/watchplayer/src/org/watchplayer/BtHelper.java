package org.watchplayer;

import android.bluetooth.BluetoothA2dp;
import android.bluetooth.BluetoothAdapter;
import android.bluetooth.BluetoothDevice;
import android.bluetooth.BluetoothProfile;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;

import java.lang.reflect.Method;
import java.util.ArrayList;
import java.util.List;
import java.util.Set;

/** Discovery, bonding and A2DP connect. The Settings UI on this build is not
 *  reachable without a touchscreen, so the app drives the stack itself. */
public class BtHelper {

    public interface Listener {
        void onBtChanged(String status);
    }

    public static class Dev {
        public final BluetoothDevice device;
        public String name;
        public boolean bonded;

        Dev(BluetoothDevice d) {
            device = d;
            String n = null;
            try { n = d.getName(); } catch (Exception e) { /* ignore */ }
            name = (n == null || n.length() == 0) ? d.getAddress() : n;
            bonded = (d.getBondState() == BluetoothDevice.BOND_BONDED);
        }
    }

    private final Context ctx;
    private final Listener listener;
    private final BluetoothAdapter adapter;
    private final List<Dev> found = new ArrayList<Dev>();

    private BluetoothA2dp a2dp;
    private BluetoothDevice pendingConnect;
    private String status = "";
    private boolean registered = false;

    public BtHelper(Context c, Listener l) {
        ctx = c.getApplicationContext();
        listener = l;
        adapter = BluetoothAdapter.getDefaultAdapter();
    }

    public String status() { return status; }
    public List<Dev> devices() { return found; }
    public boolean available() { return adapter != null; }

    private void say(String s) {
        status = s;
        if (listener != null) listener.onBtChanged(s);
    }

    public void start() {
        if (adapter == null) { say("No Bluetooth"); return; }
        if (registered) return;
        IntentFilter f = new IntentFilter();
        f.addAction(BluetoothDevice.ACTION_FOUND);
        f.addAction(BluetoothDevice.ACTION_NAME_CHANGED);
        f.addAction(BluetoothDevice.ACTION_BOND_STATE_CHANGED);
        f.addAction("android.bluetooth.device.action.PAIRING_REQUEST");
        f.addAction(BluetoothAdapter.ACTION_DISCOVERY_STARTED);
        f.addAction(BluetoothAdapter.ACTION_DISCOVERY_FINISHED);
        f.addAction(BluetoothA2dp.ACTION_CONNECTION_STATE_CHANGED);
        ctx.registerReceiver(receiver, f);
        registered = true;

        if (!adapter.isEnabled()) adapter.enable();
        adapter.getProfileProxy(ctx, proxyListener, BluetoothProfile.A2DP);
        loadBonded();
    }

    public void stop() {
        if (!registered) return;
        try { ctx.unregisterReceiver(receiver); } catch (Exception e) { /* ignore */ }
        registered = false;
        cancelScan();
    }

    private void loadBonded() {
        if (adapter == null) return;
        Set<BluetoothDevice> set = adapter.getBondedDevices();
        if (set == null) return;
        for (BluetoothDevice d : set) addOrUpdate(d);
    }

    public void scan() {
        if (adapter == null) return;
        if (!adapter.isEnabled()) { adapter.enable(); say("Turning Bluetooth on"); return; }
        found.clear();
        loadBonded();
        if (adapter.isDiscovering()) adapter.cancelDiscovery();
        boolean ok = adapter.startDiscovery();
        say(ok ? "Scanning..." : "Scan failed");
    }

    public void cancelScan() {
        try { if (adapter != null && adapter.isDiscovering()) adapter.cancelDiscovery(); }
        catch (Exception e) { /* ignore */ }
    }

    private void addOrUpdate(BluetoothDevice d) {
        for (int i = 0; i < found.size(); i++) {
            if (found.get(i).device.getAddress().equals(d.getAddress())) {
                Dev existing = found.get(i);
                String n = null;
                try { n = d.getName(); } catch (Exception e) { /* ignore */ }
                if (n != null && n.length() > 0) existing.name = n;
                existing.bonded = (d.getBondState() == BluetoothDevice.BOND_BONDED);
                return;
            }
        }
        found.add(new Dev(d));
    }

    /** Pair with a known address, whether or not it is in the current scan. */
    public void pairAddress(String addr) {
        if (adapter == null || addr == null) return;
        try {
            BluetoothDevice d = adapter.getRemoteDevice(addr.toUpperCase());
            addOrUpdate(d);
            pairAndConnect(d);
        } catch (Exception e) {
            say("Bad address");
        }
    }

    /** Bond if needed, then bring up A2DP. */
    public void pairAndConnect(BluetoothDevice d) {
        if (adapter == null) return;
        cancelScan();
        pendingConnect = d;
        if (d.getBondState() == BluetoothDevice.BOND_BONDED) {
            say("Connecting audio...");
            connectA2dp(d);
        } else {
            say("Pairing...");
            try {
                d.createBond();
            } catch (Exception e) {
                say("Pair failed");
            }
        }
    }

    public void connectA2dp(BluetoothDevice d) {
        if (a2dp == null) {
            adapter.getProfileProxy(ctx, proxyListener, BluetoothProfile.A2DP);
            pendingConnect = d;
            return;
        }
        try {
            // setPriority and connect are @hide at this API level.
            try {
                Method sp = a2dp.getClass().getMethod("setPriority", BluetoothDevice.class, int.class);
                sp.invoke(a2dp, d, 1000);   // PRIORITY_AUTO_CONNECT
            } catch (Exception ignored) { /* older stacks lack it */ }

            Method m = a2dp.getClass().getMethod("connect", BluetoothDevice.class);
            Object r = m.invoke(a2dp, d);
            say(Boolean.FALSE.equals(r) ? "Audio connect refused" : "Connecting audio...");
        } catch (Exception e) {
            say("A2DP unavailable");
        }
    }

    public BluetoothDevice connectedAudio() {
        if (a2dp == null) return null;
        List<BluetoothDevice> l = a2dp.getConnectedDevices();
        return (l == null || l.isEmpty()) ? null : l.get(0);
    }

    private final BluetoothProfile.ServiceListener proxyListener = new BluetoothProfile.ServiceListener() {
        public void onServiceConnected(int profile, BluetoothProfile proxy) {
            if (profile != BluetoothProfile.A2DP) return;
            a2dp = (BluetoothA2dp) proxy;
            if (pendingConnect != null
                    && pendingConnect.getBondState() == BluetoothDevice.BOND_BONDED) {
                connectA2dp(pendingConnect);
            }
        }
        public void onServiceDisconnected(int profile) {
            if (profile == BluetoothProfile.A2DP) a2dp = null;
        }
    };

    private final BroadcastReceiver receiver = new BroadcastReceiver() {
        public void onReceive(Context c, Intent in) {
            String a = in.getAction();
            BluetoothDevice d = in.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE);

            if (BluetoothDevice.ACTION_FOUND.equals(a) || BluetoothDevice.ACTION_NAME_CHANGED.equals(a)) {
                if (d != null) { addOrUpdate(d); say(status); }

            } else if (BluetoothAdapter.ACTION_DISCOVERY_FINISHED.equals(a)) {
                say(found.isEmpty() ? "Nothing found" : "Scan done");

            } else if ("android.bluetooth.device.action.PAIRING_REQUEST".equals(a)) {
                autoPair(d, in.getIntExtra("android.bluetooth.device.extra.PAIRING_VARIANT", 0));

            } else if (BluetoothDevice.ACTION_BOND_STATE_CHANGED.equals(a)) {
                int st = in.getIntExtra(BluetoothDevice.EXTRA_BOND_STATE, -1);
                if (d != null) addOrUpdate(d);
                if (st == BluetoothDevice.BOND_BONDED) {
                    say("Paired");
                    if (d != null) connectA2dp(d);
                } else if (st == BluetoothDevice.BOND_NONE) {
                    say("Pairing failed");
                }

            } else if (BluetoothA2dp.ACTION_CONNECTION_STATE_CHANGED.equals(a)) {
                int st = in.getIntExtra(BluetoothProfile.EXTRA_STATE, -1);
                if (st == BluetoothProfile.STATE_CONNECTED) say("Audio connected");
                else if (st == BluetoothProfile.STATE_DISCONNECTED) say("Audio disconnected");
            }
        }
    };

    /** Cheap headsets almost always use a fixed 0000 PIN and Just Works SSP. */
    private void autoPair(BluetoothDevice d, int variant) {
        if (d == null) return;
        try {
            if (variant == 0) {                       // PAIRING_VARIANT_PIN
                byte[] pin;
                try {
                    Method conv = BluetoothDevice.class.getMethod("convertPinToBytes", String.class);
                    pin = (byte[]) conv.invoke(null, "0000");
                } catch (Exception e) {
                    pin = "0000".getBytes("UTF-8");
                }
                Method sp = BluetoothDevice.class.getMethod("setPin", byte[].class);
                sp.invoke(d, (Object) pin);
            } else {                                  // consent / passkey confirmation
                Method sc = BluetoothDevice.class.getMethod("setPairingConfirmation", boolean.class);
                sc.invoke(d, true);
            }
            try {
                Method noUi = BluetoothDevice.class.getMethod("cancelPairingUserInput");
                noUi.invoke(d);
            } catch (Exception ignored) { /* fine */ }
        } catch (Exception e) {
            say("Auto-PIN failed");
        }
    }
}
