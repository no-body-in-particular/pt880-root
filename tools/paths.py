#!/usr/bin/env python3
"""Where the tools look for the working files.

These scripts operate on a scratch workspace that is deliberately *not* part of
the repo: multi-megabyte partition dumps, the stock FDL blobs pulled off the
device, and the Alpine armhf tree the system image gets its binaries from.

The layout is unchanged from how it grew, only anchored to the current user's
home instead of one hardcoded account:

    ~/wpull/
        dump_watch2/    partition images read from and written to the device
        fdl_sl8521e/    stock and patched FDL1/FDL2 blobs
        tools_arm/      armhf binaries staged into system.img
            alpine/     unpacked Alpine rootfs they are taken from

Point PT880_WORKSPACE somewhere else to move all of it at once.
"""
import os

WPULL = os.environ.get("PT880_WORKSPACE") or os.path.join(
    os.path.expanduser("~"), "wpull")

W2 = os.path.join(WPULL, "dump_watch2")
FDLDIR = os.path.join(WPULL, "fdl_sl8521e")
TOOLS_ARM = os.path.join(WPULL, "tools_arm")


def wp(*parts):
    """A path under the workspace root."""
    return os.path.join(WPULL, *parts)


def w2(*parts):
    """A path under the partition-image directory."""
    return os.path.join(W2, *parts)


def fdl(*parts):
    """A path under the FDL blob directory."""
    return os.path.join(FDLDIR, *parts)


def arm(*parts):
    """A path under the staged armhf tools directory."""
    return os.path.join(TOOLS_ARM, *parts)
