# RMS v3 as-built report package

The Word, PDF, and `report.md` exports are the historical 20 October 2026 source snapshot at baseline `9d996919f74f8c4e73d6fe77804146a9c2c7acb6`. Do not present them as an audit of the current repository. The focused [2 November deployment-readiness source audit](2026-11-02-deployment-readiness-audit.md) covers the PHP requirement, template provisioning, generation gate, session/HTTPS settings, and password-cost configuration in the working tree based on commit `46926a90a5a38040e6b560107da5d52d588ff9aa`, including this remediation's uncommitted changes. It is static source inspection, not runtime certification or a complete refresh of this report.

The Word and PDF reports are generated from `report-source.json`. Read `RMS-v3-As-Built-Report.docx` for editing or `RMS-v3-As-Built-Report.pdf` for sharing. `report.md` contains the same narrative and tables in plain text.

Evidence references such as [E03] resolve through `evidence-index.csv` and the report's evidence appendix. `source-snapshot.json` records hashes of the explicitly inspected application/documentation sources, not a clean-tree certification or a live database snapshot. No investigation files or environment values are included.

`diagrams/` contains editable SVGs, PNGs used by Word, and diagram specifications. The diagrams describe code relationships; they are not screenshots or observed execution traces.

To regenerate the exports after editing `report-source.json`, run `python docs/reports/rms-v3-as-built/build_report.py`. This reads only report assets and the frozen evidence metadata, writes only inside this folder, and invokes a local System.Drawing renderer for diagram images. It does not start a browser, connect to the application/database, install dependencies, or run tests. The PDF writer uses standard fonts and creates static pages without browser automation. Word and PDF contain the same sections, tables, figures, and evidence text; pagination may differ.

Based on static source inspection only. No tests or database commands were run. The separate RMS E2E safety investigation was not modified or used as report evidence.
