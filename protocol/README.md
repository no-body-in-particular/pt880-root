# Tracker protocol on the l009 / C42 watch

What `com.enqualcomm.support` speaks to its server, how much of it the public
specification covers, and how to reproduce every claim here.

Analysed build: `/system/priv-app/L009_Protocol.apk` + `.odex`, `versionName`
`2408072134`, pulled from this unit. Server observed live:
`193.24.208.184:9000`.

Everything below is derived from the firmware on this watch. Nothing is
inferred from what a tracker "usually" does.

Per-unit identifiers are redacted: `<device-id>` is the id the watch sends in
its login and heartbeat frames, `<password>` is the SMS command password. The
password in particular gates the microphone and camera commands in section 6,
so it does not belong in a repository even when it is the factory default.
The server address is left in - it is vendor infrastructure, not something
that identifies this unit.

## TL;DR

- The watch speaks **Thinkrace IW** (`IWAP`/`IWBP`), **not** the widely-posted
  `[3G*<id>*<len>*LK]` SeTracker/Wonlex protocol. A `[3G*]`-style doc describes
  a different protocol — one this firmware also contains but is not running.
- The app ships **eight** protocol implementations. The live one is
  **`protocol_beehome`**, selected by `persist.sys.protocol_no=1`.
- The active protocol has **53 uplink + 53 downlink** opcodes. The public spec
  documents **42 + 42**. **21 uplink and 21 downlink opcodes are undocumented.**
- A second, entirely separate **SMS control plane** exists: **36** commands in
  firmware, **11** in the spec, so **25 undocumented** — including remote mic,
  remote camera, arbitrary modem AT commands, and server/protocol reconfiguration.

## 1. Framing

Captured on the live socket with `tcpdump -i seth_lte0 -A`:

```
IWBPXL,<device-id>,080835#     server -> watch
IWAPXL,080835#                 watch  -> server
IWAP03,<id>,0,00,8,600#        watch  -> server
```

    IW  <dir><opcode> , <field> , <field> ... #

`AP` = uplink (watch to server), `BP` = downlink (server to watch). Opcode is
two characters, either digits (`03`, `85`) or letters (`XL`, `TM`, `H1`).
Fields are comma-separated, the frame ends with `#`. Plaintext, no TLS, no
authentication beyond the device id in the login frame.

## 2. Eight protocols in one binary

`com.ic.protocols.*` contains:

| Class | Style | Uplink | Downlink |
|---|---|---|---|
| `protocol_s123` (Vitrack) | IWAP/IWBP | 59 | 58 |
| `protocol_ic` | IWAP/IWBP | 55 | 55 |
| **`protocol_beehome`** (active) | IWAP/IWBP | **53** | **53** |
| `protocol_jiai` | IWAP/IWBP | 37 | 37 |
| `protocol_anan` | IWAP/IWBP | 30 | 33 |
| `protocol_gator` | IWAP/IWBP | 27 | 28 |
| `protocol_fzd` | named commands | — | — |
| `protocol_mqtt` | named commands, MQTT | — | — |

`protocol_fzd` and `protocol_mqtt` are where the SeTracker-style named commands
live, which is why they turn up in a string dump of this APK and mislead you
into thinking the watch speaks that protocol:

- `fzd`: `LK PING KA UD UD2 AL WT WT2 WG DLT TKQ VOICE RECORD MONITOR CALL CENTER UPLOAD FLOWER INFO BLE LGZONE img temp rcapture`
- `mqtt`: `BREQ KA LGZONE UD AL ALARM temp heart blood oxygen UPLOAD hrtstart wdstart CENTER MONITOR SOS FACTORY REMOVE PEDO MSGDOWN`

**Counting the union of all eight is the easy mistake.** It yields 95/93
opcodes and an apparently enormous documentation gap. The honest comparison is
one protocol against its own spec — see §4.

### Which one is live

`Config.getProtocolNumber()` reads the system property `persist.sys.protocol_no`.
`ICSmsManager.handleSMS_CMD` writes it, and its literals give the mapping
unambiguously:

| Value | Protocol |
|---|---|
| 0 | jiai |
| **1** | **beehome** |
| 2 | ic |
| 3 | fzd |
| 4 | mqtt |

The handler also says `setprotocol only support Protocol 0/1/2/3/4 by now!`,
while `Config.getProtocolNumber` logs a list of eight (`Gator`, `S123 Vitrack`,
`ANAN`, `MQTT`, `FZD`, `IC`, `BEEHOME`, `JIAI`) and a message meaning "protocol
version, currently supports 0,1,2,3,4,5,6,7". Indices 5-7 are therefore
reachable by property but not by SMS, and their mapping is **not confirmed**.

`build.prop` in this unit's `system.img` has:

    persist.sys.protocol_no=1
    persist.sys.ota.host2=http://ota.beehome360.com/checkota.aspx

so the active protocol is **beehome**, corroborated by the OTA host in the same
file. Confirm on a running device with `getprop persist.sys.protocol_no`.

## 3. Active protocol opcodes (`protocol_beehome`)

Uplink (53):

    AP00 AP01 AP02 AP03 AP05 AP07 AP10 AP12 AP14 AP15 AP16 AP17 AP18
    AP21 AP28 AP31 AP32 AP33 AP34 AP40 AP42 AP46 AP49 AP68 AP75 AP84
    AP85 AP86 AP87 AP88 AP89 AP92 APH1 APHP APHT APJK APJZ APMC APOX
    APPH APS4 APSM APSQ APTE APTF APTM APTP APTQ APU8 APVR APX1 APXL APXY

Downlink (53): the same 53 suffixes with a `BP` prefix.

**53 is the enum, not the behaviour.** `CMD_TYPE` has 53 downlink entries but
only **45** of them have a `handleBP*` method:

    BP00 BP01 BP02 BP03 BP05 BP07 BP12 BP14 BP15 BP16 BP17 BP18 BP28 BP31 BP32
    BP33 BP34 BP40 BP42 BP46 BP68 BP75 BP84 BP85 BP86 BP87 BP88 BP89 BPHP BPJZ
    BPMC BPOX BPPH BPS4 BPSM BPSQ BPTE BPTF BPTM BPTP BPTQ BPU8 BPX1 BPXL BPXY

`BP10 BP21 BP49 BP92 BPH1 BPHT BPJK BPVR` are recognised by the parser and then
dropped on the floor. Ask "what will the watch act on" and the answer is 45.

## 4. Gap against the public specification

Reference: [Thinkrace IW protocol V2.10, 2025-04-20](https://www.thinkrace.com/wp-content/uploads/2025/05/IW-protocol_Thinkrace_V2.10-20250420.pdf),
46 pages — 42 uplink and 42 downlink command IDs.

**Undocumented in the active protocol — 21 uplink:**

    AP02 AP05 AP07 AP21 AP28 AP68 AP75 AP85 AP87 AP89 AP92
    APH1 APHP APJZ APOX APS4 APSQ APTE APTM APU8 APX1

**Undocumented — 21 downlink:** the same suffixes with `BP`.

The spec is not a superset either. It defines ten pairs this firmware does not
implement: `04 19 20 50 51 52 BL WL WR XZ`. It documents a different
generation, not a parent set.

## 5. What the undocumented opcodes do

Recovered from the ordered `const-string` sequence in
`protocol_beehome.handleCmd` — the dispatcher logs a description next to each
branch. Chinese log text translated, original in parentheses where it carries
the opcode.

| Opcode | Meaning | Evidence |
|---|---|---|
| `AP00` | login / registration | "server received login packet AP00", `login` |
| `AP01` | location report | "server received location AP01" |
| **`AP02`** | location report, second form | "server received location AP02" |
| `AP03` | heartbeat / keepalive | "server received heartbeat packet AP03", `heartbeat` |
| **`BP05`** | server push; QR-code image receive | "received server BP05", "received QR code image" |
| `BP12` | set SOS numbers | "server set SOS number command BP12" |
| `BP14` | set whitelist numbers | "server set whitelist number command BP14" |
| `BP15` | set location mode / interval | "server set location command BP15", `StartLocate` |
| `BP17` | factory reset | "server factory reset command BP17", `FactoryReset` |
| `BP18` | reboot | "server reboot command BP18", `Reboot` |
| **`BP28`** | server voice message (audio push) | "received server voice BP28" |
| `BP31` | power off | "server power-off command BP31", `poweroff` |
| `BP32` | server-initiated dial | "received backend dial command BP32" |
| **`BP75`** | set alarm clocks | `handleBP75` writes `content://com.ic.provider.alarm/alarm`, fields `alarmName`, `alarmTime`, `isAllOpen`, `alarmCount` |
| **`BP85`** | set alarm | "server set alarm command BP85" |
| **`APH1` / `BPH1`** | historical location upload | "server received historical location BPH1" |
| `APHT` | upload heart rate + blood pressure | "server received uploaded heart rate and blood pressure" |
| **`APTM`** | time sync | "server received time-sync command APTM" |
| `APJK` | health data report | "server received health data report" |
| **`APX1` / `BPX1`** | set HR / BP / SpO2 correction values | "server set heart rate, blood pressure, blood oxygen correction value BPX1" |
| `AP86` | find-device | `findDev` |

Same dispatcher, features still without a resolved opcode: `showTxt` (server
pushes text to the screen), `setWorkMod`, "take photo immediately", server
changing `skipmovecheck`, changing the time format, reminder time-windows,
whitelist commands, and the BP / SpO2 / heart-rate detection triggers.

Two that were on that list are no longer: the vital-sign detection interval is
`BPSQ` and blood-pressure calibration is `BPJZ`, both below.

### Resolved by their downlink handlers

The dispatcher has no descriptive string for these, but each one's `handleBP*`
does. Recovered by decoding the `invoke` instructions rather than only the
`const-string` ones, and inverting that into a caller index: the frame builders
just format their arguments, so the meaning lives in the handler and in who
calls it. Chinese log text translated, original where it carries the evidence.

| Opcode | Meaning | Evidence in `handleBP*` |
|---|---|---|
| `AP05` / `BP05` | voice messages waiting on the server | "the server has N voice messages not received" |
| `AP07` / `BP07` | **voice / media packet, and its acknowledgement** | `SentVoicePacket`, `totalPackageCount`, `currentPackage` |
| `AP68` / `BP68` | device binding / activation state | "bound successfully", "not activated or unbound" |
| `AP87` / `BP87` | QR-code URL push | "BP87: Qrcode url", writes `persist.sys.qrUriFile` |
| `AP89` / `BP89` | heart-rate / blood-pressure alarm thresholds | "server command: heart rate / blood pressure threshold" |
| `APHP` / `BPHP` | heart rate + blood pressure + SpO2 upload | "server received APHP heart rate blood pressure blood oxygen" |
| `APJZ` / `BPJZ` | blood pressure, systolic and diastolic | `BPH`, `BPL` per index |
| `APOX` / `BPOX` | blood oxygen | `Handle BPOX IMEI ,index ,cmdname` |
| `APS4` / `BPS4` | alarm clocks, up to seven | `set 4th alarm` ... `set 7th alarm` |
| `APTP` / `BPTP` | body temperature | "BPTP server received body temperature data" |
| `APU8` / `BPU8` | text-message tunnel, also carries the photo trigger | `received Txt Msg111`, `>*photo@1*<`, `CameraUtilRe` |
| `APX1` / `BPX1` | sensor calibration offsets | `Config.setPPGAdjust`, `setBPHAdjust`, `setBPLAdjust`, `setSPo2Adjust` |

### Dead rather than unknown

`AP21`, `AP92` and `APH1` have a builder and an enum entry and **no caller
anywhere in the application**. So do the uplink halves of `AP87`, `APHP` and
`APTP`: the watch handles the downlink but never sends the matching frame.
Calling them unresolved overstates it. There is nothing to resolve, because
nothing invokes them.

### Media packets are not text

`AP42` (picture) and `AP07` (voice) share a five-field header and are
**length-delimited, not `#`-delimited**:

    IWAP42,<yyyymmddhhmmss>,<total packets>,<packet no>,<length>,<length bytes>#

The payload is raw JPEG or AMR and contains `NUL`, `#` and `,` constantly, so
anything treating it as text truncates the file at the first `#`. Packets are
1-based and 1024 bytes, the last one short.

The acknowledgement that advances an upload is **`BP07`, not the `BP42` the
manual documents**. The picture is pushed through the voice-packet sender, and
the only place its position index is written is `handleBP07`; `handleBP42`
parses the reply, logs it, and never touches the index. A client waiting on
`BP42` sends packet one and then stops forever, having been acknowledged at
the TCP level the whole time. `device_server/device/thinkrace_protocol.c` in
CTracker has the server side of this.

## 6. The SMS control plane

A second control channel, independent of the TCP protocol, handled by
`ICSmsManager.handleSMS_CMD`. Format:

    <password>#<command>#

Observed working on this unit: `<password>#reboot#` inbound, `do reboot ok!` sent
back. Authentication is that shared password over unauthenticated SMS.

**Documented in the spec (11):** `admin_number`, `apn`, `deviceinfo`,
`factoryreset`, `location`, `maillog`, `poweroff`, `reboot`, `setlocation`,
`status`, `wifictl`.

**Undocumented (25):**

| Command | Effect |
|---|---|
| `#monitor#`, `#listen#` | silent microphone listen |
| `#capture#` | camera capture (`ACTION_REMOTE_CAMERA`) |
| `#atcmd#=` | arbitrary AT command to the modem |
| `#host#=`, `#ip#=` | repoint the device to another server |
| `#setfotasrv#=`, `#setlogsrv#=` | change firmware-update and log servers |
| `#setprotocol#=` | switch protocol implementation; **reboots the device** |
| `#usb#=` | USB mode (`mtp` / `none`) |
| `#tp#=getver`, `#tp#=upgrade` | read or reflash the FT6236U touch-panel controller's firmware; the handler refuses anything but an A1-to-A3 upgrade |
| `#logctl#=`, `#packagevers#`, `#showinfo#`, `#showui#`, `#testcmd#` | diagnostics, log upload |
| `#fotaupdate#`, `#fotaupdatewifi#` | trigger firmware update |
| `#reset#`, `#factoryresetchecksim#`, `#poweroffchecksim#` | reset / power-off variants |
| `#getweather#`, `#skipmovecheck#=`, `#nolocationcycle#=`, `#en_cutalarm_repeat#=` | feature toggles |

This is the most security-relevant surface on the device: anyone who knows the
watch's number and the password gets mic, camera, modem, and the ability to
point it at a server of their choosing.

Related knobs found alongside: `persist.sys.protocol_IMEI` (overrides the
reported IMEI), `setProtocolServerHost` / `setProtocolServerPort`,
`/system/etc/protocol_config.json`, `persist.sys.JsonConfigFilePath`.

## 7. What it actually does, vs what it can do

String presence proves capability, not use. Android 4.4 keeps per-op last-use
timestamps, so `dumpsys appops` answers the stronger question. On this unit:

```
GPS:                          (running)
MONITOR_HIGH_POWER_LOCATION:  (running)
FINE_LOCATION:  +645ms ago
WIFI_SCAN:      +7m57s ago
NEIGHBORING_CELLS: +7m59s ago
CAMERA:         +532d ago; duration=+2s721ms
RECEIVE_SMS / SEND_SMS: +1h12m ago
```

- **No `RECORD_AUDIO` entry at all** — the microphone has never been used by
  this app. `#monitor#` / `#listen#` exist but have not run.
- Camera: once, 532 days ago, 2.7 s.
- Positioning is not GPS-only: WiFi access points and neighbouring cell IDs are
  scanned on the same cadence, so surrounding-network data goes up with the
  coordinates.
- The SMS pair is the operator's own `<password>#reboot#`.

## 8. Reproducing this

```bash
adb pull /system/priv-app/L009_Protocol.apk
adb pull /system/priv-app/L009_Protocol.odex
python tools/dexmap.py                       # extract + cross-reference
```

`dexmap.py` walks the Dalvik instruction stream properly rather than scanning
for `0x1a` bytes, because `0x1a` is a common data byte and a naive scan invents
string references. Two traps it exists to avoid:

1. **This is an ODEX.** The embedded dex is rewritten with Dalvik *quick*
   opcodes in `0xe3-0xff`, a range unused in a plain dex. Sizing those as one
   code unit desyncs the walk in 84% of methods. With the correct table the
   walk completes on all 26,686 methods with **zero** desyncs — that clean
   number is the correctness check.
2. **adbd is Thumb-2.** An ARM-only branch decoder finds no callers for
   anything, including functions whose patches demonstrably work. Any
   "unreferenced" claim from a scanner must be validated against a call site
   known to be reachable before it is believed. See NOTES.md §6.

Live protocol capture:

```bash
adb shell /system/xbin/tcpdump -i seth_lte0 -s 0 -A -n -l -c 20 host <server-ip>
```

`-i any` yields nothing on this kernel; the LTE interface is `seth_lte0`
(arptype 530, so libpcap falls back to a cooked socket, which is fine).

`opcodes.json` in this directory is the machine-readable form of §3-§5.

## 9. Confidence

- **Framing, server, live opcodes** — observed on the wire. Certain.
- **Opcode inventories, protocol selection, SMS command set** — read out of the
  binary by instruction-accurate cross-reference. Certain, subject to the note
  below.
- **Opcode semantics in §5** — inferred from the dispatcher's own log strings.
  Strong, but they are the vendor's descriptions, not verified by sending the
  commands.
- **The twelve resolved from `handleBP*`** - read out of the handlers' own log
  strings by instruction-accurate cross-reference, the same method and the same
  standard as the rest. Strong, but they are the vendor's descriptions.
- **`AP21` / `AP92` / `APH1` as dead code** - certain. A caller index over every
  method in the dex finds nothing that invokes them.
- The inventory counts opcodes referenced *from code*. A command handled by a
  branch that never runs would still be counted. Nothing here proves every
  opcode is reachable at runtime.

## 10. The on-device sensor service (`com.ic.work`)

Not part of the tracker protocol — this is the binder interface the vendor's own
apps use to reach the sensors, and it is on the same device. Recorded here
because working it out took a day and the code that used it has been removed.

`com.ic.work.SensorDataService` is declared `exported="true"` with **no
permission**, so any app on the watch can bind it.

**Binding it needs the right action, and the manifest does not have it.** An
explicit component alone returns null from `onBind` while `bindService` still
answers true, and so does the resolver table's
`action.WORK_SERVICE_SECOND_TIMER` - that one starts its timer. `onBind`'s own
constants have the real pair:

    "on service bind package name -- > "  " action name is == > "
    "com.ic.blood"   "com.ic.sensor.data.action.HEART_RATE"
    "com.ic.temp"    "com.ic.sensor.data.action.TEMPERATURE"

Two binders behind one service, chosen by action. With `HEART_RATE` the bind
returns `com.ic.work.IHeartRateSensorService`, and a measurement actually starts.

**And it is the only thing that starts one.** `gh30x_sensor` in the platform
sensor list is a mirror: `registerListener` replays its cached triple at once,
`requestTriggerSensor` returns without the HAL doing anything, and `dumpsys`
shows `0 active connections` with a `last=` that does not move for hours. A
client reading it directly gets the same frozen numbers for ever. Through the
binder the sensor goes to `status: active`, the LED comes on, and the value
changes.

```
interface com.ic.work.IHeartRateSensorService
  1  registerCallback(IHeartRateSensorCallback, String pkg)   writes a reply
  2  unRegisterCallback(IHeartRateSensorCallback)             writes a reply
  3  getHeartRateInfo(int from, String pkg)                   writes NO reply

interface com.ic.work.IHeartRateSensorCallback
  1  onHeartRateGet(HeartRate)     3  onGettingData()
  2  onHeartRateUpdate(HeartRate)  4  onWaiting()

HeartRate parcel: a null-flag int, then five ints in this order
  oxygen, from, heartRate, bloodHeight, bloodLow
```

Transaction 3 returns out of `onTransact` without `writeNoException`, so a
synchronous call leaves the caller reading an empty reply — it must go
`FLAG_ONEWAY`. Transactions 1 and 2 do write a reply and must not.

`from` is echoed back in the result and is otherwise free, so a caller can tell
its own readings from the firmware's scheduled ones.
`HeartRateOxygenTestType`: 0 ALL, 1 JUST\_OXYGEN, 2 JUST\_HEART\_RATE.

### Why nothing uses it

The service keeps **one work queue for both sensors** — heart rate and
temperature — with a single worker taking items through `handleTestEvent`. Each
item carries a creation timestamp, and that timestamp is the only
`currentTimeMillis()` call in the service: it is written and never compared. So
there is no timeout, and a measurement whose sensor callback never arrives holds
the queue permanently. Both sensors stop within a minute of each other and stay
stopped until the process restarts.

That makes this interface useless as a *recovery* route - asking it for a reading
during a stall only queues another item behind the stuck one - but it is the only
route there is, because the platform sensor cannot start a measurement at all.
So it is used, with a bounded wait, no retry after a failure, and a doubling
backoff so a watch that is off the wrist stops asking rather than taking a turn
on that queue every three minutes all night. The newer version
of the interface has a `stopCurrentWork` that would be the escape hatch; the
build on this watch does not implement it — the binder answers three
transactions and that is not one of them.

Reaching the same hardware through the platform's `SensorManager`
(`gh30x_sensor`) is a different route that does not touch this queue.

### Reproducing

```
baksmali deodex -a 19 -d <framework-odexes> -o out ICL02WorkService.odex
```

The `-a 19` is not optional. Without it roughly 1800 classes fail with
truncated-instruction errors — including the ones that matter — and the result
looks like a much smaller, much simpler app than it is.
