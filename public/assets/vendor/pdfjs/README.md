# PDF.js vendor files

RMS bundles the browser runtime from `pdfjs-dist` version `6.4.299` for authenticated, same-origin research document viewing and annotation. The matching worker and supporting CMaps, ICC profiles, image decoders, standard fonts, WASM modules, viewer stylesheet, and images are kept beside the runtime files.

The package is distributed under the Apache License, Version 2.0; see [LICENSE](LICENSE). The application does not load PDF.js or submitted documents from a third-party host.

To refresh this bundle, install the exact version in the repository `package.json` and copy the runtime files and supporting directories from `node_modules/pdfjs-dist` into this directory. Keep the main bundle and worker from the same package version.
