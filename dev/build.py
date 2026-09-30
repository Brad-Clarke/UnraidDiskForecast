"""Builds the installable plugin: archive/diskforecast-<version>-noarch-1.txz and plugin/diskforecast.plg.

Usage:
    python dev/build.py [--version 2026.09.28] [--git-url URL] [--changes "- What changed"]

The .txz holds everything under source/, owned by root, with Slackware package permissions.
The .plg is written from plugin/diskforecast.plg.template with the version, download URL and
the package's MD5 filled in.
"""

import argparse
import datetime
import hashlib
import io
import pathlib
import tarfile
import time

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "source"
PLUGIN_DIR = "usr/local/emhttp/plugins/diskforecast"
EXECUTABLE_DIRS = (f"{PLUGIN_DIR}/scripts/",)
DEFAULT_GIT_URL = "https://raw.githubusercontent.com/Brad-Clarke/UnraidDiskForecast/main"


def add(tar, arcname, data=None, directory=False, mtime=None):
    info = tarfile.TarInfo(arcname)
    info.uid = info.gid = 0
    info.uname = info.gname = "root"
    info.mtime = mtime or int(time.time())
    if directory:
        info.type = tarfile.DIRTYPE
        info.mode = 0o755
        tar.addfile(info)
        return
    info.size = len(data)
    info.mode = 0o755 if arcname.startswith(EXECUTABLE_DIRS) else 0o644
    tar.addfile(info, io.BytesIO(data))


def build_package(version):
    archive_dir = ROOT / "archive"
    archive_dir.mkdir(exist_ok=True)
    package = archive_dir / f"diskforecast-{version}-noarch-1.txz"
    plugin_root = SOURCE / PLUGIN_DIR
    with tarfile.open(package, "w:xz", format=tarfile.GNU_FORMAT) as tar:
        add(tar, PLUGIN_DIR, directory=True)
        for path in sorted(plugin_root.rglob("*")):
            arcname = path.relative_to(SOURCE).as_posix()
            if path.is_dir():
                add(tar, arcname, directory=True)
            else:
                data = path.read_bytes()
                if path.suffix in (".php", ".page", ".js", ".css"):
                    data = data.replace(b"\r\n", b"\n")
                add(tar, arcname, data)
    return package


def write_plugin(version, git_url, changes, md5):
    template = (ROOT / "plugin" / "diskforecast.plg.template").read_text(encoding="utf-8")
    plugin = (
        template.replace("@VERSION@", version)
        .replace("@GIT_URL@", git_url)
        .replace("@MD5@", md5)
        .replace("@CHANGES@", f"###{version}\n{changes}")
    )
    target = ROOT / "plugin" / "diskforecast.plg"
    target.write_text(plugin, encoding="utf-8", newline="\n")
    return target


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--version", default=datetime.date.today().strftime("%Y.%m.%d"))
    parser.add_argument("--git-url", default=DEFAULT_GIT_URL)
    parser.add_argument("--changes", default="- First release: forecasts, graph, settings, dashboard tile and warnings.")
    args = parser.parse_args()

    package = build_package(args.version)
    md5 = hashlib.md5(package.read_bytes()).hexdigest()
    plugin = write_plugin(args.version, args.git_url, args.changes, md5)
    print(f"Package: {package.relative_to(ROOT)} ({package.stat().st_size:,} bytes, md5 {md5})")
    print(f"Plugin:  {plugin.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
