"""
Implementation of Face Tracker.
Uses simple IoU matching and lapjv for linear assignment to avoid AGPL code.
"""

import logging
import numpy as np
import lap

logger = logging.getLogger(__name__)

def iou_batch(bboxes1, bboxes2):
    """
    Compute IoU between two sets of bounding boxes.
    bboxes1: [N, 4] (x1, y1, x2, y2)
    bboxes2: [M, 4] (x1, y1, x2, y2)
    Returns: [N, M] cost matrix (1 - IoU)
    """
    if len(bboxes1) == 0 or len(bboxes2) == 0:
        return np.zeros((len(bboxes1), len(bboxes2)))

    # Compute areas
    area1 = (bboxes1[:, 2] - bboxes1[:, 0]) * (bboxes1[:, 3] - bboxes1[:, 1])
    area2 = (bboxes2[:, 2] - bboxes2[:, 0]) * (bboxes2[:, 3] - bboxes2[:, 1])

    # Compute intersections
    lt = np.maximum(bboxes1[:, None, :2], bboxes2[:, :2])
    rb = np.minimum(bboxes1[:, None, 2:], bboxes2[:, 2:])
    wh = np.clip(rb - lt, 0, None)
    inter = wh[:, :, 0] * wh[:, :, 1]

    # Compute IoU and cost
    union = area1[:, None] + area2 - inter
    iou = inter / np.clip(union, 1e-6, None)
    return 1.0 - iou

class Track:
    _id_count = 1
    
    def __init__(self, bbox):
        self.track_id = Track._id_count
        Track._id_count += 1
        self.bbox = bbox
        self.time_since_update = 0

    def update(self, bbox):
        self.bbox = bbox
        self.time_since_update = 0

    def mark_missed(self):
        self.time_since_update += 1

class FaceTracker:
    def __init__(self, track_thresh=0.5, match_thresh=0.8, track_buffer=30, frame_rate=30):
        self.track_thresh = track_thresh
        self.match_thresh = match_thresh
        self.track_buffer = track_buffer
        self.tracks = []
        
    def update_frame_rate(self, frame_rate: int):
        pass

    def update(self, face_detections: list[dict], frame_rate: int | None = None) -> list[dict]:
        if not face_detections:
            for track in self.tracks:
                track.mark_missed()
            self.tracks = [t for t in self.tracks if t.time_since_update < self.track_buffer]
            return []

        # Parse detections
        dets = []
        valid_faces = []
        valid_indices = []
        for i, face in enumerate(face_detections):
            bbox = face.get("bbox", {})
            if not bbox or not isinstance(bbox, dict):
                continue
            x, y = bbox.get("x", 0), bbox.get("y", 0)
            w, h = bbox.get("width", 0), bbox.get("height", 0)
            if w <= 0 or h <= 0:
                continue
            
            score = face.get("confidence", 1.0)
            if score < self.track_thresh:
                continue
                
            dets.append([x, y, x + w, y + h])
            valid_faces.append(face)
            valid_indices.append(i)

        if not dets:
            for track in self.tracks:
                track.mark_missed()
            self.tracks = [t for t in self.tracks if t.time_since_update < self.track_buffer]
            return face_detections

        dets_array = np.array(dets, dtype=np.float32)

        matched_det_indices = set()
        
        if self.tracks:
            track_bboxes = np.array([t.bbox for t in self.tracks], dtype=np.float32)
            cost_matrix = iou_batch(track_bboxes, dets_array)
            
            # Use lapjv for assignment
            _, x, y = lap.lapjv(cost_matrix, extend_cost=True, cost_limit=1.0 - self.match_thresh)
            
            for track_idx, det_idx in enumerate(x):
                if det_idx >= 0:
                    self.tracks[track_idx].update(dets_array[det_idx])
                    matched_det_indices.add(det_idx)
                    valid_faces[det_idx]["track_id"] = self.tracks[track_idx].track_id
        
        # Unmatched tracks get missed
        for i, track in enumerate(self.tracks):
            if i not in (x if self.tracks else []):
                track.mark_missed()
                
        # Unmatched detections become new tracks
        for i, det_bbox in enumerate(dets_array):
            if i not in matched_det_indices:
                new_track = Track(det_bbox)
                self.tracks.append(new_track)
                valid_faces[i]["track_id"] = new_track.track_id
                
        # Remove old tracks
        self.tracks = [t for t in self.tracks if t.time_since_update < self.track_buffer]
        
        # Build results
        result_by_index = {}
        for original_idx, face in zip(valid_indices, valid_faces):
            result_by_index[original_idx] = face
            
        result = []
        for i, face in enumerate(face_detections):
            if i in result_by_index:
                result.append(result_by_index[i])
            else:
                face_copy = face.copy()
                face_copy["track_id"] = -(i + 1)
                result.append(face_copy)
                
        return result
