# Root from an app, and why wsu cannot provide it

State of play as of 27 August, written to be picked up on a machine with debug
access to the watch.

## The problem this exists to solve

`com.ic.work` runs one work queue for both sensors with no timeout on the item
at its head — see `protocol/README.md` §10. A measurement whose sensor callback
never arrives holds that queue permanently, and heart rate and temperature stop
together until the process restarts. Measured on this watch: eight stalls in one
day, mean 33 minutes each, roughly a fifth of the day with no vitals.

Restarting `com.ic.work` clears it in seconds. Rebooting the whole watch also
clears it and is what the server currently does, thirty minutes late, with a
dark screen and a lost GPS fix each time.

`PpgWatchdog` tries the cheap fix and fails every time for want of root.

**Its detection is also broken, and that matters more.** Read this before
granting it root.

The watchdog decides the watch has gone quiet by listening for the vendor's own
result broadcasts, `com.ic.action.BLOOD_HEART` and
`com.enqualcomm.support.ACTION_BROADCAST_PPG`, and stamping a preference on each
one. It has never received a single one. Measured on 27 August at 22:28 UTC: the
server had 227 minutes of unbroken readings while the watchdog reported "no
pulse reading for 106 min", its counter climbing steadily from the moment the
launcher started. Earlier trips reading "11 min" and "21 min" were 11 and 21
minutes after a launcher restart, not detections.

So every trip so far has been spurious, roughly one every fifteen minutes,
harmless only because the recovery is a no-op without root.

**Grant root without fixing this and the daemon will restart `com.ic.work` every
fifteen minutes on a false signal**, breaking a sensor stack that was working.
Fix the detection first, or use a design that does not depend on it.

## Why the launcher cannot get root

Not for want of a helper. `wsu` is present, setuid, and works:

```
$ adb shell ls -l /system/xbin/wsu
-rwsr-sr-x root root 401944 wsu

$ adb shell "/system/xbin/busybox setuidgid 10048 /system/xbin/wsu id"
uid=0(root) gid=0(root)
```

10048 is the launcher's own uid, so that is not a permissions problem. Run from
*inside* the app it fails, and this is why:

```
$ adb shell cat /proc/<launcher-pid>/status
Uid:     10048 10048 10048 10048
CapEff:  0000000000000000
CapBnd:  0000000000000000
```

Zygote clears the capability bounding set on every process it forks, and sets
securebits alongside it. Under those, exec'ing a setuid-root binary does not
grant root — `wsu` runs as 10048, its `setuid(0)` returns EPERM, and it exits.
The restriction is applied before `wsu` starts, so no change to `RootShell` can
route around it.

Ruled out along the way, each with a command:

| suspected | check | result |
| --- | --- | --- |
| `/system` mounted `nosuid` | `mount \| grep system` | `ro,relatime,data=ordered` — not it |
| SELinux denial | `getenforce`, `logcat \| grep avc` | `Disabled`, no denials |
| `wsu` broken or mis-installed | `busybox setuidgid 10048 wsu id` | returns `uid=0` |
| app uid not permitted | same command | same |

`build_boot_capbnd.py` does not help here. It neuters `adbd`'s capability drop,
which fixes the *shell*. Apps are restricted by zygote, not by adbd.

## The way through

`tools/build_boot_rootsvc.py` builds a boot image with an init-started service.
Init does not restrict what it starts — measured in that file's own survey,
`sh` forked from init has `CapBnd = 0000003fffffffff` — so the service has full
capabilities and can do what the app cannot.

It currently polls `/data/local/tmp/rootcmd` and runs it with `sh`. That path is
not writable by an app, so it works for adb and not for the launcher.

### Three designs, and the one to build

**Arbitrary command file on an app-writable path.** Add
`/sdcard/Documents/rootcmd` to the poll loop. Simple, and hands root to anything
on the device that can write `/sdcard` — which is everything. Started this and
stopped: it is a real escalation and should be a deliberate choice, not a wiring
detail.

**Flag file, fixed action.** The daemon polls for a flag file and, when it
appears, does exactly one hardcoded thing: restart `com.ic.work`. No shell
evaluation. Anything that can create the flag can restart the sensor service and
nothing else — a nuisance rather than an escalation. Detection stays in the
launcher, which is the weakness: see above, it has never received one of the
broadcasts it counts on, so it would be asking for a restart every fifteen
minutes whether or not anything was wrong.

**Self-contained daemon.** No app involvement at all. The firmware's `ICLogger`
writes to logcat under tag `log`, and `HeartRateManager$2` logs 心率测试结果来了
on every completed measurement, so the daemon can tail logcat, stamp a file on
each result, and restart `com.ic.work` when that stamp goes stale. Most locked
down of the three. The weakness is that it depends on matching a localised log
string that has been read in the disassembly but not confirmed to reach logcat
at runtime — worth verifying first with:

```
adb logcat -v time -s log:* | grep -a 心率
```

Recommended: **the self-contained daemon**, on the evidence above. The flag-file
design was recommended here on the strength of the launcher's detection being
reliable, and it is not - the launcher has never seen one of the broadcasts it
counts on. A daemon that decides for itself does not inherit that fault.

If the flag-file design is built anyway, the launcher's detection has to be
fixed and verified first: confirm with `adb logcat` that those broadcasts are
actually sent, and that a registered receiver in another app receives them.
Until that is demonstrated, treat any trip from `PpgWatchdog` as noise.

### If you build it

- `build_boot_rootsvc.py` reads `boot_stock_exact.img` from `paths.w2()` and
  writes `boot_rootsvc_fdl.img` beside it. The stock image is in the repo at
  `firmware/stock/boot_stock_exact.img`; copy it there first.
- It also neuters `adbd`'s uid/gid drop, so adb root survives the change.
- Keep `--restore` in mind: `patch-watch-ppg.sh` remounts `/system` rw and back.
  That was suspected of breaking setuid and did not — the mount flags are clean —
  but it is the kind of thing worth re-checking after any boot image change.

## What works today without any of this

The stalls are now much rarer, and neither change needed root:

- `SleepService` stands back around the firmware's three minute measurement
  window instead of colliding with it.
- The watchdog no longer asks `SensorDataService` for a reading, which used to
  queue work behind the item already stuck.

Longest clean run before those: 197 minutes, in a day averaging 59. After: 253
minutes and counting when this was written. Suggestive, not proven — a night
would settle it, and the Sleep log toggle in the Watch menu gives a clean A/B.
