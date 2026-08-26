/*
 * A recovery update-binary that does one thing: add the properties that turn on adb over
 * the network, by appending them to /system/build.prop.
 *
 * Recovery runs this as
 *     update-binary <api version> <command pipe fd> <package.zip>
 * and takes a zero exit as success.
 *
 * Freestanding on purpose. There is no updater binary anywhere on this device to borrow,
 * recovery's /sbin carries no shell so the usual "ship a shell script" trick does not work,
 * and the alternative was to download a stranger's binary and let it run as root on a watch
 * that cannot be recovered without a cable. This is small enough to read in one sitting:
 * raw syscalls, no libc, no allocation, no zip handling.
 *
 * It only ever appends. A write that dies half way leaves a truncated final line, and
 * Android's property loader skips lines it cannot parse - so the failure mode is "the
 * setting did not take", never "the file is gone". It never truncates, never renames, and
 * never touches anything else in /system.
 *
 * Idempotent: if the marker is already in build.prop it changes nothing and exits 0.
 */

#define SYS_exit     1
#define SYS_read     3
#define SYS_write    4
#define SYS_open     5
#define SYS_close    6
#define SYS_sync    36
#define SYS_mount   21
#define SYS_umount2 52

#define O_RDONLY  0
#define O_WRONLY  1
#define O_APPEND  02000

#define MS_RDONLY   1
#define MS_REMOUNT 32

typedef unsigned int  u32;
typedef int           i32;

static inline i32 sys3(i32 n, long a, long b, long c) {
    register long r7 __asm__("r7") = n;
    register long r0 __asm__("r0") = a;
    register long r1 __asm__("r1") = b;
    register long r2 __asm__("r2") = c;
    __asm__ volatile("svc #0" : "+r"(r0) : "r"(r7), "r"(r1), "r"(r2) : "memory");
    return (i32)r0;
}

static inline i32 sys5(i32 n, long a, long b, long c, long d, long e) {
    register long r7 __asm__("r7") = n;
    register long r0 __asm__("r0") = a;
    register long r1 __asm__("r1") = b;
    register long r2 __asm__("r2") = c;
    register long r3 __asm__("r3") = d;
    register long r4 __asm__("r4") = e;
    __asm__ volatile("svc #0" : "+r"(r0) : "r"(r7), "r"(r1), "r"(r2), "r"(r3), "r"(r4) : "memory");
    return (i32)r0;
}

static i32 s_open(const char * p, i32 fl)                   { return sys3(SYS_open, (long)p, fl, 0); }
static i32 s_read(i32 fd, void * b, u32 n)                  { return sys3(SYS_read, fd, (long)b, n); }
static i32 s_write(i32 fd, const void * b, u32 n)           { return sys3(SYS_write, fd, (long)b, n); }
static i32 s_close(i32 fd)                                  { return sys3(SYS_close, fd, 0, 0); }
static void s_sync(void)                                    { sys3(SYS_sync, 0, 0, 0); }
static void s_exit(i32 c)                                   { sys3(SYS_exit, c, 0, 0); for (;;) {} }
static i32 s_umount2(const char * t, i32 f)                 { return sys3(SYS_umount2, (long)t, f, 0); }
static i32 s_mount(const char * src, const char * tgt, const char * fs, u32 fl, const void * d) {
    return sys5(SYS_mount, (long)src, (long)tgt, (long)fs, fl, (long)d);
}

static u32 s_len(const char * s) {
    u32 n = 0;

    while (s[n]) {
        n++;
    }

    return n;
}

/* substring search, so the marker check does not need libc */
static int contains(const char * hay, u32 hlen, const char * needle) {
    u32 nlen = s_len(needle);

    if (nlen == 0 || hlen < nlen) {
        return 0;
    }

    for (u32 i = 0; i + nlen <= hlen; i++) {
        u32 j = 0;

        while (j < nlen && hay[i + j] == needle[j]) {
            j++;
        }

        if (j == nlen) {
            return 1;
        }
    }

    return 0;
}

/* Overridable so the logic can be exercised under qemu against a sandbox file, which is
   how this was tested before it was ever pointed at a watch. */
#ifndef SYSTEM_DEV
#define SYSTEM_DEV  "/dev/block/platform/soc/by-name/system"
#endif
#ifndef SYSTEM_DIR
#define SYSTEM_DIR  "/system"
#endif
#ifndef BUILD_PROP
#define BUILD_PROP  "/system/build.prop"
#endif
#define MARKER      "# ---- pt880 remote adb ----"

static const char ADDITION[] =
    "\n"
    MARKER "\n"
    "persist.sys.usb.config=adb\n"
    "service.adb.tcp.port=5555\n"
    "ro.adb.secure=0\n"
    "ro.debuggable=1\n";

static void say(i32 pipefd, const char * msg) {
    if (pipefd > 0) {
        s_write(pipefd, msg, s_len(msg));
    }

    s_write(2, msg, s_len(msg));
}

int main(int argc, char ** argv) {
    i32 pipefd = 0;

    /* argv[2] is the fd recovery listens on for progress lines */
    if (argc > 2) {
        const char * p = argv[2];

        while (*p >= '0' && *p <= '9') {
            pipefd = pipefd * 10 + (*p++ - '0');
        }
    }

    /* Already mounted is the common case and not an error; a fresh mount is tried first and
       a remount second, and neither failing is fatal because the open below is the real
       test of whether /system is writable. */
    s_mount(SYSTEM_DEV, SYSTEM_DIR, "ext4", 0, "");
    s_mount(SYSTEM_DEV, SYSTEM_DIR, "ext4", MS_REMOUNT, "");

    i32 fd = s_open(BUILD_PROP, O_RDONLY);

    if (fd < 0) {
        say(pipefd, "ui_print could not open build.prop\n");
        s_exit(1);
    }

    static char buf[65536];
    u32 got = 0;

    for (;;) {
        i32 n = s_read(fd, buf + got, sizeof(buf) - got);

        if (n <= 0) {
            break;
        }

        got += (u32)n;

        if (got >= sizeof(buf)) {
            break;
        }
    }

    s_close(fd);

    if (contains(buf, got, MARKER)) {
        say(pipefd, "ui_print adb properties already present\n");
        s_sync();
        s_exit(0);
    }

    fd = s_open(BUILD_PROP, O_WRONLY | O_APPEND);

    if (fd < 0) {
        say(pipefd, "ui_print build.prop is not writable\n");
        s_exit(1);
    }

    u32 want = s_len(ADDITION);
    u32 done = 0;

    while (done < want) {
        i32 n = s_write(fd, ADDITION + done, want - done);

        if (n <= 0) {
            break;
        }

        done += (u32)n;
    }

    s_close(fd);
    s_sync();

    if (done != want) {
        say(pipefd, "ui_print short write appending to build.prop\n");
        s_exit(1);
    }

    say(pipefd, "ui_print adb over tcp enabled, port 5555\n");
    s_umount2(SYSTEM_DIR, 0);
    s_exit(0);
    return 0;
}

/* no libc, so this is the entry point: pull argc/argv off the stack and call main */
__asm__(
    ".global _start\n"
    "_start:\n"
    "  ldr r0, [sp]\n"
    "  add r1, sp, #4\n"
    "  bl  main\n"
    "  mov r7, #1\n"
    "  svc #0\n"
);
