# system/

Files that live on the device under `/system`, kept here as the record of what
is actually running.

## `etc/mkshrc`

Pulled from the watch with `adb pull /system/etc/mkshrc`. It is **stock mksh rc
+ the block appended by `tools/customize_shell.py`**, and it is not edited by
hand here — `customize_shell.py` regenerates it into `system_mod.img`, so change
the generator, not this file.

Two things in it are easy to get wrong and were both got wrong first:

- **The TERM default has to be changed in the stock line, not appended.** The
  stock rc opens with `: ${TERM:=vt100} ...`, and `:=` only assigns when the
  variable is unset. A second `: ${TERM:=xterm-256color}` further down can
  therefore never fire — it reads as correct and does nothing, leaving TERM as
  vt100 (no colour, no ACS line-drawing, htop unreadable). `customize_shell.py`
  now rewrites the stock line itself; that is the single edit it makes to
  otherwise byte-preserved upstream text.
- **No external commands.** The device has no `printf` and no `id`, and
  `/system/xbin` is not on PATH until this file has already been sourced. See
  the comments in the appended block.
