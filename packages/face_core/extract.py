import sys
import json
import base64
import cv2
import numpy as np
import onnxruntime as ort

from preprocess import align_faces_batch, preprocess_batch
from postprocess import normalize_embeddings_batch
from session_utils import init_face_recognizer_session
from face_detector.detector import FaceDetector

def main():
    if len(sys.argv) < 4:
        print(json.dumps({"error": "Usage: extract.py <detector_path> <recognizer_path> <image_path>"}), file=sys.stderr)
        sys.exit(1)
        
    detector_path = sys.argv[1]
    recognizer_path = sys.argv[2]
    image_path = sys.argv[3]
    
    img = cv2.imread(image_path)
    if img is None:
        print(json.dumps({"error": "Could not read image"}), file=sys.stderr)
        sys.exit(1)

    # Load models
    detector = FaceDetector(
        model_path=detector_path,
        input_size=(320, 240),
        conf_threshold=0.6,
        nms_threshold=0.3,
        top_k=5000,
        min_face_size=20,
        edge_margin=10
    )
    
    session, input_name = init_face_recognizer_session(recognizer_path, ["CPUExecutionProvider"], None)

    # Detect faces
    faces = detector.detect_faces(img)
    if not faces:
        print(json.dumps({"error": "No face detected"}), file=sys.stderr)
        sys.exit(1)
    
    if len(faces) > 1:
        print(json.dumps({"error": "Multiple faces detected. Please upload a photo with only one face."}), file=sys.stderr)
        sys.exit(1)

    face = faces[0]
    
    # Extract embedding
    aligned_faces = align_faces_batch(img, [{"landmarks_5": face["landmarks_5"]}], (112, 112))
    if not aligned_faces:
        print(json.dumps({"error": "Face alignment failed"}), file=sys.stderr)
        sys.exit(1)
        
    batch_input = preprocess_batch(aligned_faces, 127.5, 127.5)
    feeds = {input_name: batch_input}
    
    outputs = session.run(None, feeds)
    embeddings = outputs[0]
    normalized = normalize_embeddings_batch(embeddings)
    
    emb = normalized[0]
    # Output raw bytes (float32 array) base64 encoded to stdout
    emb_bytes = emb.astype(np.float32).tobytes()
    b64_out = base64.b64encode(emb_bytes).decode('ascii')
    
    # Output JSON to stdout so it's robust
    result = {
        "success": True,
        "embedding_b64": b64_out
    }
    print(json.dumps(result))
    sys.exit(0)

if __name__ == "__main__":
    main()
