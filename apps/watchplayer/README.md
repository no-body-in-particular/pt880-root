# watchplayer

A local-file music player for the rooted **l009 / C42** kids' watch
(Spreadtrum SL8521E, Android 4.4.4, API 19), playing to Bluetooth headphones.

Built for a 240×240 screen with **no touchscreen** and two hardware buttons.

## Device facts that shaped the design

| | |
|---|---|
| Screen | 240×240 px, density 120 (ldpi) |
| Touchscreen | **none** — `dumpsys input` reports `TouchDeviceId: -1`. Injected taps do nothing. |
| Buttons | `KEY_ENTER` (scancode 28) and `KEY_POWER` (116) |
| Bluetooth | Classic BR/EDR + BLE, full AOSP stack, A2DP works |
| Storage | `/sdcard` → `/storage/emulated/legacy`, 2.3 GB free |

### The button quirk

The firmware does **not** hand the main key to apps normally:

* a **tap** is swallowed entirely and re-emitted as a synthetic `BACK`
  (`deviceId=-1`) — the app never sees `DPAD_CENTER` at all;
* a **hold** leaks `DPAD_CENTER` auto-repeats first, and *then* still ends in
  that same synthetic `BACK`.

So `BACK` is the only reliable "key released" signal, and the auto-repeat count
seen beforehand is the only measure of how long the key was held. The app keys
its whole gesture model off that, and swallows `BACK` so it can never quietly
close the app. Two rapid taps are collapsed into a single `BACK` by the
firmware, which is why there is no double-tap gesture.

## Controls

Button **A** is the main key; button **B** is the former power key.

Now playing:

| Gesture | Action |
|---|---|
| A tap | play / pause |
| A hold | open menu |
| B tap | next track |
| B hold | previous track |

In a list, B moves and A commits:

| Gesture | Action |
|---|---|
| A tap | select |
| A hold | back out |
| B tap | move down |
| B hold | move up |

**A hold is any hold.** Length is deliberately not overloaded: people hold a
watch button for five or six seconds, and an earlier build that treated a
longer hold as a separate gesture just meant the menu could never be reached
in practice. The only place hold length still matters is backing out of a list
in one-button mode, and every list also carries a `Back` item so the gesture is
never required.

The app also works with **button A alone** (tap = play/pause, hold = menu; in
lists tap moves and hold selects). It starts in that mode and switches to the
two-button scheme the first time it sees a B press, so it degrades gracefully
if the remap below is reverted.

## Closing the app, and getting back in

The stock launcher is a **dead-end clock face** — no app list, and the buttons
do not navigate it. So closing the player strands you unless there is another
way back. There are two:

* **play/pause on the headphones** reopens the player UI from anywhere. Skip
  and previous deliberately do not, or the screen would jump up on every track
  change.
* `adb shell am start -n org.watchplayer/.PlayerActivity`

`Menu → Exit app` is a real quit: it stops playback, stops the service and
closes the UI. `Menu → Back` just returns to the now-playing screen.

The headphones' own buttons work too — play/pause, next and previous over
AVRCP, plus volume (see below).

## Adding music

```bash
adb push "yourfile.mp3" /sdcard/Music/
```

Then **Menu → Rescan music**. The app walks the filesystem directly rather than
using `MediaStore`, so files show up immediately without waiting for the media
scanner. It picks up mp3, m4a, aac, wav, ogg, flac and a few others.

## Bluetooth

Pairing is done inside the app (**Menu → Bluetooth**) because the stock Settings
Bluetooth screen cannot be driven without a touchscreen — D-pad focus does not
move in it, and its list only renders the rows that fit, so most discovered
devices are invisible.

The bond is a **normal system-level Android pairing**, not app-local: the app
calls `createBond()`, so the link key is written to bluedroid's `bt_config.xml`
and the connection is owned by `com.android.bluetooth`. It survives reboots and
reconnects on its own, and every app on the watch outputs to the headphones.
The app auto-answers the pairing request with a `0000` PIN / Just Works
confirmation and then connects A2DP through the hidden
`BluetoothA2dp.connect()`, setting priority to auto-connect.

If the buds are not advertising, they can still be paired by address:

```bash
adb shell am start -n org.watchplayer/.PlayerActivity -e pair AA:BB:CC:DD:EE:FF
```

Substitute your own buds' address. An i35 reports DevClass `0x240404`
(Audio/Video → wearable headset); the address appears in **Menu → Bluetooth**,
or in `/data/misc/bluedroid/bt_config.xml` after a scan.

## Building

No Gradle — the modern AGP stack fights an API 19 target, so the build calls the
SDK tools directly (`aapt` → `javac` → `d8` → `zipalign` → `apksigner`):

```bash
bash build.sh
adb install -r watchplayer.apk
```

Requires the Android SDK build-tools and a JDK; paths are set at the top of
`build.sh`. It runs on both Windows/MSYS and Linux — the SDK ships `.exe`/
`.bat` wrappers on the former and bare executables on the latter, and the
script probes for which. Set `ANDROID_SDK_ROOT` if the SDK is not in one of
the usual places.

## System files changed

Three key layouts under `/system/usr/keylayout/` are edited:

```bash
bash apply-keylayout.sh
```

`keylayout/*.orig` here are the stock files and `keylayout/*.kl` the patched
ones actually running; the script also leaves a `.orig` backup on the device,
so a revert needs nothing but adb. A reboot is required either way — layouts
are only read when an input device is added.

| File | Change | Why |
|---|---|---|
| `gpio-keys.kl` | `key 116` `POWER` → `DPAD_DOWN WAKE` | hands the power key to apps as button B |
| `Generic.kl` | same | `sprd-keypad` has no layout of its own and falls back to this |
| `AVRCP.kl` | added `key 115 VOLUME_UP` / `key 114 VOLUME_DOWN` | the stock file maps only play/pause/next/prev, so the headphones' volume buttons never reached Android |

Two keycodes were tried for button B and rejected:

* `VOLUME_UP` — collides with the headphones' own volume buttons;
* `MENU` — the vendor app `com.xrs.bluetooth_device` claims it as a hotkey and
  steals focus, popping a toast with the watch's MAC.

`DPAD_DOWN` is claimed by nothing else on this build.

### Cost of the remap

The power button **no longer sleeps or powers off the watch**. What remains:

* `adb reboot` / `adb reboot -p`
* holding the button long enough for the PMIC's hardware force-off
* the screen still sleeps on its own timeout, and any button press wakes it
  (both keys carry `WAKE`)

To undo it:

```bash
bash restore-power-button.sh
```

That restores all three layouts from the on-device `.orig` copies and reboots.
The app falls back to its one-button scheme automatically — clear its data
(`adb shell pm clear org.watchplayer`) to reset the remembered mode.

## Volume

`STREAM_MUSIC` is shown as a drawn bar on the now-playing screen (views, not
block characters — this build's font has no `U+25AE`/`U+25AF`, so a text bar
renders blank). It is polled once a second, so volume moved by anything else —
the system, AVRCP absolute volume, another app — still shows up.

Note that Android 4.4 predates AVRCP absolute volume, so on many cheap TWS buds
the volume buttons only change the buds' own internal level and the watch never
sees them. The `AVRCP.kl` additions cover the case where the buds send volume
as AVRCP passthrough instead.

## Status bar

A one-line bar sits across the top of **every** screen — now playing, menu and
Bluetooth alike — with the clock on the left and the battery on the right:

```
10:42                    84%
```

The clock follows the system 12/24-hour setting (`DateFormat.getTimeFormat`)
and reticks every second along with the rest of the UI.

The battery is read from the sticky `ACTION_BATTERY_CHANGED` broadcast, not
`BatteryManager.getIntProperty()` — that only landed in API 21 and this watch
is 19. Registering for the same broadcast means the reading is pushed on change
rather than polled. It needs no permission.

A charging watch shows a trailing `+` and turns blue; at or below 15% the
percentage turns red. The `+` is deliberately not a bolt glyph, for the same
reason the volume bar is drawn rather than typed: this build's font is missing
most of the symbol range.
