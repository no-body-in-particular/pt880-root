# The watch's command set, as the firmware actually implements it

Everything here was read out of the watch's own code rather than a datasheet:
`system.img` → `/priv-app/L009_Protocol.odex`, deodexed against the device's
framework with `baksmali deodex -a 19 -d <framework>`. Where the vendor protocol
document and the firmware disagree, the firmware is what the watch does.

The tracker server that speaks to it lives in the `CTracker` repository; command
names in the first column are what that server accepts.

## Which protocol this watch speaks

`L009_Protocol` carries eight protocol families and picks one at runtime from
`Config.protocol_no`:

    anan  beehome  fzd  gator  ic  jiai  mqtt  s123

This watch runs **beehome**, which is the "IW" protocol - packets look like
`IWAP01,...#` upward and `IWBP01,...#` downward. `#setprotocol#=` switches
families, but the server would then need a parser for whichever was chosen, and
no document exists for most of them.

## Protocol commands

Each handler in `protocol_beehome` checks the number of comma separated fields
before doing anything, so that count *is* the format. Three fields means imei and
serial number, four adds one value, five adds two.

| Server command | Wire form | Fields | What the watch does | Confirmed |
|---|---|---|---|---|
| `SYNCTIME#` | `IWBP00,<time>,<tz>#` | - | set the clock | in use |
| `LOCATE#` | `IWBP16,<imei>,<serial>#` | 3 | fix now | in use |
| `RESTART#` | `IWBP18,<imei>,<serial>#` | 3 | reboot | in use |
| `SHUTDOWN#` | `IWBP31,<imei>,<serial>#` | 3 | power off | in use |
| `FACTORYALL#` | `IWBP17,<imei>,<serial>#` | 3 | factory reset | in use |
| `HEARTRATE#` | `IWBPXL,<imei>,<serial>#` | 3 | heart rate reading | in use |
| `UPDATE=<s>#` | `IWBP15,<imei>,<serial>,<s>#` | 4 | location interval, seconds | in use |
| `MODE=<n>#` | `IWBP33,<imei>,<serial>,<n>#` | 4 | working mode | in use |
| `TIMES=<hhmm@hhmm>#` | `IWBP34,<imei>,<serial>,1,<v>#` | 5 | working hours | in use |
| `MSG=<text>#` | `IWBP40,<imei>,<serial>,<hex>#` | 4 | text message, hex encoded | in use |
| `PHOTO#` | `IWBP46,<imei>,<serial>,1#` | 4 | take a picture | **yes** — answers `IWAP46`, picture follows |
| `RECORD#` | `IWBPSM,<imei>,<serial>,@monitor@#` | 4 | record ten seconds and upload | **yes** — 15334 byte AMR, 9.889 s |
| `SPO2#` | `IWBPOX,<imei>,<serial>,1#` | 4 | blood oxygen reading | **yes** — answers `IWAPOX` |
| `TEMP#` | `IWBPTE,<imei>,<serial>,1#` | 4 | temperature reading | **yes** — answers `IWAPTE` |
| `MOTION=0\|1` | `IWBPMC,<imei>,<serial>,<v>#` | 4 | motion detection | **yes** — answers `IWAPMC` |
| `PHONE=0\|1` | `IWBPPH,<imei>,<serial>,<v>#` | 4 | call switch, sets `persist.sys.phone.enable` | **yes** — answers `IWAPPH` |
| `HOURS=12\|24` | `IWBPTF,<imei>,<0\|1>#` | 3 | clock format, 1 is 24 hour | **yes** — answers `IWAPTF` |
| `HEALTHINT=<hr>,<bp>` | `IWBP86,<imei>,<serial>,<hr>,<bp>#` | 5 | heart rate and blood pressure periods, minutes | **yes** — answers `IWAP86` |
| `SMS=<cmd>` | `IWBPSM,<imei>,<serial>,<escaped>#` | 4 | run one of the `#...#` commands | **yes** — via `RECORD#` |

`HOURS=` is the odd one: three fields and no serial number, which is what `handleBPTF`
checks for and what the watch answers to.

**Every packet must end in `#`.** The older commands take that character from
whatever the caller typed, so `UPDATE=600#` works and `UPDATE=600` goes out
unterminated - the watch waits for an end of packet that never arrives and
discards the whole thing silently. Confirmed on hardware: `IWBP86,...,30,60`
drew no reply, `IWBP86,...,30,60#` answered `IWAP86,080835`.

## Pictures, and why the manual is wrong about them

The document says a picture packet is acknowledged with `BP42`. The watch parses
that reply, logs it, and does nothing with it.

The picture is not sent by a picture routine at all. `sendPicture()` switches on
`Config.protocol_no` and is only implemented for `protocol_fzd`; for beehome it
returns immediately. What actually transmits is the **voice packet sender** -
`getVoicePacketItem()` calls `protocol_beehome.AP42()`, and `Deliver` logs it as
`"voicepacket Sender"`. Its position is `voicePacket.currentIndexToSent`, and
across the whole beehome protocol that field is written from exactly one place:

    handleBP07 — advances only when "1".equals(field[4]) && field[3] != field[2]

with `field[2]` the total packet count and `field[3]` the packet just received.
So the acknowledgement the watch is waiting for is:

    IWBP07,<device time>,<total packets>,<packet number>,1#

Send `BP42` alone and the upload stops dead after packet one, having acknowledged
you at the TCP level. Send `BP07` and it runs to completion.

Two more things the document does not mention:

* **The packet id is wrong.** The type reaches the builder empty and is printed
  through a `%s`, so packets arrive as `IWnull,...` rather than `IWAP42,...`.
  Accept both.
* **The payload is hex.** A 1024 byte chunk arrives as 2048 characters of
  `ffd8ffe0...`, and the length field counts characters, not bytes.

## Health readings: telling the watch, rather than asking it

The watch will report heart rate, blood pressure, temperature and blood oxygen on a schedule
of its own once `BP86` has set the period, and does not need to be asked for each reading.
Twenty polls an hour becomes one command every few hours, and its radio can stay down in
between.

    HEALTHINT=<heart rate>,<blood pressure>     both in minutes

Readings then arrive unprompted as `IWAPJK` - type 1 is heart rate and blood pressure as
`<hr>|<bp>`, 2 is heart rate, 3 is temperature, 4 is blood oxygen.

Two things follow from the watch keeping its own schedule. The period has to be re-sent
occasionally, because a watch that was reset or never took the command would otherwise stay
silent forever with nothing to notice. And a gap in readings means less than it used to: no
poll went unanswered, the watch simply did not speak - so whatever restarts a device that has
gone quiet should be given a good deal more rope than a poll interval would suggest.

## Recording

There is no protocol command for it. The trigger is in the SMS handler, where
`#monitor#` calls `DevliverStringMessage("StartMonitor", "10")`; that parameter is
multiplied by 1000 and used as a delay, so it records for **ten seconds**. The
chain is `AudioService.startRecord()` → wait → `stopRecord()` → *"Monitor Record
to send"*, and the file goes up the same voice packet path a picture uses.

So `RECORD#` sends `#monitor#` through the SMS tunnel below.

The upload can arrive under `IWAP07`, `IWAP42` or the broken `IWnull`, so the id
cannot tell a recording from a picture. The bytes can: a JPEG opens `FF D8 FF`,
an AMR file opens with the literal `#!AMR`.

`#listen#<number>` is a different feature - it issues `atd<number>;` and the watch
**telephones** that number. That is a call, not an upload.

## The SMS tunnel

`BPSM` runs one of the watch's SMS commands over the data connection, so none of
them need an actual text message. From `handleBPSM`: four comma separated fields,
and the last is rewritten `@`→`#` and `-`→`,` before being handed to the SMS
handler - because `#` ends a packet and `,` separates fields, so neither can
appear raw.

    IWBPSM,<imei>,<serial>,<command with @ for # and - for ,>#

The vocabulary, from `ICSmsManager`:

    #status#  #deviceinfo#  #ShowInfo#  #packagevers#  #showui#  #location#
    #capture#            take a picture, the same camera path BP46 uses
    #monitor#            record ten seconds and upload
    #listen#<number>     the watch telephones that number
    #setlocation#=  #nolocationcycle#=  #skipmovecheck#=
    #reboot#  #poweroff#  #poweroffCheckSim#  #factoryreset#  #reset#
    #ip#=  #host#=  #apn#=  #wifictl#=  #setprotocol#=  #TP#=
    #USB#=none|mtp|adb|mtp,adb          sets sys.usb.config
    #FotaUpdate#  #FotaUpdateWiFi#=  #setFotaSrv#=
    #atcmd#=  #testcmd#=  #logctl#=  #maillog#  #setLogSrv#=  #getWeather#
    #ADMIN_NUMBER#  #en_cutalarm_repeat#=

## Firmware update, and what guards it

`#setFotaSrv#=` repoints the update server and `#FotaUpdate#` starts a check.
`ICFotaClient` downloads a `.zip` and hands it to `RecoverySystem`. There is no
APK install path anywhere in the app - no `PackageInstaller`, no `pm install` -
so an OTA package is the only way to put software on this watch remotely.

What guards it:

* `ICFotaClient` calls `RecoverySystem.verifyPackage()` before `installPackage()`,
  against `/system/etc/security/otacerts.zip`.
* Recovery verifies again, against its own `/res/keys`.

Both hold **the AOSP public test key**, and its private half is published in
AOSP. Verified: the modulus in recovery's `res/keys` is byte for byte the modulus
of `testkey.x509.pem` in `otacerts.zip`. So a package signed with the AOSP test
key passes both checks.

What does *not* guard it:

* the default endpoint is plain HTTP - `http://icfota.snicker.com.cn:8089/upfile.jsp`
* `ICOtaApiUtils$initOkHttp$trustAllCerts$1.checkServerTrusted` is a no-op: it
  null checks its arguments and returns, so any certificate is accepted
* a custom `HostnameVerifier` replaces the default one
* `#setFotaSrv#=` is reachable by SMS, so anyone who can text the watch can
  repoint its update server

Building an OTA still needs an ARM `update-binary`; there is no `updater` in
`/system/bin`, and recovery's `/sbin` holds only `adbd healthd recovery
rsa_decrypt sec_openssl ueventd watchdogd` - no shell, so the usual trick of
shipping a shell script as the update binary does not work here.

## adb

`#USB#=adb` sets `sys.usb.config` and gives adb **over USB only**. There is no
network adb: `service.adb.tcp.port`, `adbd` and `tcpip` appear nowhere in the
app, and `build.prop` sets `ro.build.type=user` with no `ro.debuggable` or
`ro.secure` override.

Recovery is different - `init.rc` starts

    service adbd /sbin/adbd --root_seclabel=u:r:su:s0 --device_banner=recovery

so adb in recovery runs as root, but still over USB.
