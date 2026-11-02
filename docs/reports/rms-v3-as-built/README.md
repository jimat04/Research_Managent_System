# RMS v3 as-built report package

The Word and PDF reports are generated from `report-source.json`. Read `RMS-v3-As-Built-Report.docx` for editing or `RMS-v3-As-Built-Report.pdf` for sharing. `report.md` contains the same narrative and tables in plain text.

Evidence references such as [E03] resolve through `evidence-index.csv` and the report's evidence appendix. `source-snapshot.json` records hashes of the explicitly inspected application/documentation sources, not a clean-tree certification or a live database snapshot. No investigation files or environment values are included.

`diagrams/` contains editable SVGs, PNGs used by Word, and diagram specifications. The diagrams describe code relationships; they are not screenshots or observed execution traces.

To regenerate the exports after editing `report-source.json`, run `python docs/reports/rms-v3-as-built/build_report.py`. This reads only report assets and the frozen evidence metadata, writes only inside this folder, and invokes a local System.Drawing renderer for diagram images. It does not start a browser, connect to the application/database, install dependencies, or run tests. The PDF writer uses standard fonts and creates static pages without browser automation. Word and PDF contain the same sections, tables, figures, and evidence text; pagination may differ.

Based on static source inspection only. No tests or database commands were run. The separate RMS E2E safety investigation was not modified or used as report evidence.
