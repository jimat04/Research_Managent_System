"""Read-only validation of the approved EARIST packages and structural bindings."""
import hashlib
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}


def text(element):
    return "".join(node.text or "" for node in element.findall(".//w:t", NS))


def inspect(manifest):
    failures = []
    results = []
    bindings_checked = 0
    for key, spec in {**manifest["documents"], **manifest["variants"]}.items():
        path = ROOT / "templates" / "earist" / "blank" / spec["filename"]
        if path.parent.resolve() != (ROOT / "templates" / "earist" / "blank").resolve():
            failures.append(f"{key}: template path escapes approved directory")
            continue
        try:
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            if digest != spec["sha256"]:
                failures.append(f"{key}: approved SHA-256 differs")
            with zipfile.ZipFile(path) as package:
                if package.testzip() is not None:
                    failures.append(f"{key}: ZIP CRC failure")
                parts = {}
                for name in package.namelist():
                    if name.endswith((".xml", ".rels")):
                        data = package.read(name)
                        if b"<!DOCTYPE" in data.upper():
                            raise ValueError("DTD is not allowed")
                        parts[name] = ET.fromstring(data)
                body = parts["word/document.xml"]
                if len(body.findall("w:body/w:tbl", NS)) != spec["body_table_count"]:
                    failures.append(f"{key}: body table count differs")
                if len(body.findall(".//w:tbl", NS)) != spec["total_table_count"]:
                    failures.append(f"{key}: total table count differs")
                for name, expected in spec["header_footer_hashes"].items():
                    if hashlib.sha256(package.read(name)).hexdigest() != expected:
                        failures.append(f"{key}: protected header/footer changed: {name}")
                for binding in spec["bindings"]:
                    nodes = body.findall(binding["target"], NS)
                    label = ", ".join(binding["fields"])
                    if len(nodes) != binding["expected_count"]:
                        failures.append(f"{key}: ambiguous/missing target: {label}")
                    elif "expected_text" in binding and text(nodes[0]).strip() != binding["expected_text"]:
                        failures.append(f"{key}: expected blank target is populated: {label}")
                    if "guard" in binding:
                        guards = body.findall(binding["guard"], NS)
                        if len(guards) != 1 or text(guards[0]) != binding["guard_text"]:
                            failures.append(f"{key}: surrounding label changed: {label}")
                    bindings_checked += 1
                if key == "annex_a":
                    cell = body.find("./w:body/w:tbl[4]/w:tr[1]/w:tc[1]", NS)
                    if text(cell).count("☒") != 4:
                        failures.append("annex_a: documentary template-state baseline differs")
                if key == "qsf_rds_019":
                    sdg = text(body.find("./w:body/w:tbl[3]/w:tr[3]/w:tc[2]", NS))
                    if sdg.count("☐") != 2 or "8. Decent Work" not in sdg or "9. Industry" not in sdg:
                        failures.append("qsf_rds_019: printed SDG destinations differ")
                if key == "annex_f" and len(body.findall("./w:body/w:tbl[2]/w:tr", NS)) - 1 != 7:
                    failures.append("annex_f: reviewer signatory capacity differs")
                results.append({"document": key, "filename": spec["filename"], "sha256": digest,
                                "xml_parts": len(parts), "bindings": len(spec["bindings"])})
        except (OSError, KeyError, ValueError, ET.ParseError, zipfile.BadZipFile) as error:
            failures.append(f"{key}: {type(error).__name__}: {error}")
    return {"templates": results, "bindings_checked": bindings_checked, "failures": failures}


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    with (ROOT / "config" / "earist-template-bindings.json").open(encoding="utf-8") as handle:
        result = inspect(json.load(handle))
    print(json.dumps(result, ensure_ascii=False, indent=2))
    print(f"{len(result['templates'])} packages inspected; {result['bindings_checked']} bindings; "
          f"{len(result['failures'])} failures. Read only; no DOCX output.")
    sys.exit(bool(result["failures"]))
