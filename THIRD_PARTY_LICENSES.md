# Third-Party Licenses

Absensi-Cam combines several open-source frameworks, libraries, and AI models across its Backend (Laravel) and Edge Engine (Python).

## Core Frameworks and Runtimes

| Component | License | Upstream |
| --- | --- | --- |
| Laravel | MIT | https://github.com/laravel/laravel |
| PHP | PHP License | https://github.com/php/php-src |
| Python | PSF | https://github.com/python/cpython |
| Flask | BSD-3-Clause | https://github.com/pallets/flask |
| ONNX Runtime | MIT | https://github.com/microsoft/onnxruntime |
| OpenCV | Apache-2.0 | https://github.com/opencv/opencv |

## Bundled Model Components

The Edge Engine uses `.onnx` models for Face Detection and Face Recognition. These models are derived from upstream open-source research.

### Face Detection (YuNet)

- **Description:** Absensi-Cam uses YuNet as its primary face detector on the edge device due to its high efficiency on CPU-bound ARM devices.
- **Upstream Project:** [OpenCV Zoo - YuNet](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet)
- **License:** MIT License
- **Copyright:** Copyright (c) 2020 Shiqi Yu <shiqi.yu@gmail.com>

> Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software...

### Face Recognition (SFace / MobileFaceNet)

- **Description:** Absensi-Cam uses SFace (or MobileFaceNet variants) for generating 128-D or 512-D face embeddings.
- **Upstream Project:** [OpenCV Zoo - SFace](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface)
- **License:** MIT / Apache-2.0 (Depending on exact weights used).

### Open-Source Compliance

Absensi-Cam acknowledges the incredible work of the open-source community. If you believe a license is missing or improperly attributed, please open an issue so we can rectify it immediately.
