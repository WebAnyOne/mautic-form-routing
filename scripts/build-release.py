#!/usr/bin/env python3
"""Build the installable Mautic ZIP using only the Python standard library."""

import hashlib
import json
from pathlib import Path
import zipfile


def main():
    root = Path(__file__).resolve().parents[1]
    version = json.loads((root / "composer.json").read_text())["version"]
    bundle = "WebAnyOneFormRoutingBundle"
    directories = {
        "Config", "EventListener", "Form", "Resources", "Service",
        "Translations", "Views", "docs",
    }
    files = {
        bundle + ".php", "composer.json", "LICENSE.txt", "README.md", "CHANGELOG.md",
    }
    output = root / "dist"
    output.mkdir(exist_ok=True)
    archive = output / (bundle + "-" + version + ".zip")
    included = {}
    for source in sorted(root.rglob("*")):
        if not source.is_file() or source.is_symlink() or source.name == ".DS_Store":
            continue
        relative = source.relative_to(root)
        if relative.parts[0] not in directories and relative.as_posix() not in files:
            continue
        included[bundle + "/" + relative.as_posix()] = source.read_bytes()
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as target:
        for name, content in included.items():
            entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            target.writestr(entry, content)
    with zipfile.ZipFile(archive) as target:
        if target.testzip() is not None:
            raise RuntimeError("Corrupt archive")
        if any(target.read(name) != content for name, content in included.items()):
            raise RuntimeError("Archive does not match source")
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_name(archive.name + ".sha256").write_text(
        digest + "  " + archive.name + "\n"
    )
    print(json.dumps({"archive": archive.name, "files": len(included), "sha256": digest}))


if __name__ == "__main__":
    main()
