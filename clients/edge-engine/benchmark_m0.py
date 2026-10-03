import os
import sys
import time

import numpy as np

# Add current directory to path so it can import core
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from core.models.face_detector.yunet import FaceDetector


def main():
    print("M0 Benchmark: YuNet Face Detection")
    print("-----------------------------------")

    model_path = os.path.join(os.path.dirname(__file__), "models", "detector.onnx")
    if not os.path.exists(model_path):
        print(f"ERROR: Model not found at {model_path}")
        return

    print("Initializing YuNet...")
    detector = FaceDetector(model_path=model_path)

    # 320x240 image for benchmark
    print("Generating 320x240 dummy image...")
    dummy_image = np.random.randint(0, 255, (240, 320, 3), dtype=np.uint8)

    print("Warming up...")
    for _ in range(5):
        detector.detect_faces(dummy_image)

    print("Running Benchmark (100 frames)...")
    iterations = 100
    start_time = time.time()

    for _ in range(iterations):
        detector.detect_faces(dummy_image)

    end_time = time.time()
    total_time = end_time - start_time
    fps = iterations / total_time

    print("-----------------------------------")
    print(f"Total time: {total_time:.4f} seconds")
    print(f"Average FPS: {fps:.2f} FPS")
    print(f"Per-frame inference: {(total_time / iterations) * 1000:.2f} ms")

    if fps < 10:
        print("\n❌ RESULT: FAILED. FPS is too low (< 10). Python might not be viable.")
    else:
        print(
            "\n✅ RESULT: PASSED. FPS is acceptable (>= 10). Python edge engine is viable!"
        )


if __name__ == "__main__":
    main()
