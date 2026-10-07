import pytest
import numpy as np
from core.models.tracker.tracker import FaceTracker, iou_batch

def test_iou_batch():
    bboxes1 = np.array([[0, 0, 10, 10], [20, 20, 30, 30]])
    bboxes2 = np.array([[0, 0, 10, 10], [5, 5, 15, 15]])
    
    cost = iou_batch(bboxes1, bboxes2)
    # cost should be 1 - iou. 
    # [0,0,10,10] vs [0,0,10,10] -> IoU = 1.0 -> cost = 0.0
    assert np.isclose(cost[0, 0], 0.0)
    
    # [0,0,10,10] vs [5,5,15,15] -> inter is [5,5,10,10] (area 25). union is 100+100-25=175.
    # IoU = 25/175 = 1/7 = 0.1428
    assert np.isclose(cost[0, 1], 1.0 - (25/175))
    
    # disjoint
    assert np.isclose(cost[1, 0], 1.0)

def test_tracker_lifecycle():
    tracker = FaceTracker(track_thresh=0.5, match_thresh=0.5, track_buffer=2)
    
    # Frame 1: New ID
    detections = [{"bbox": {"x": 10, "y": 10, "width": 50, "height": 50}, "confidence": 0.9}]
    results = tracker.update(detections)
    assert len(results) == 1
    track_id_1 = results[0]["track_id"]
    assert track_id_1 > 0
    
    # Frame 2: Same ID (moved slightly)
    detections = [{"bbox": {"x": 12, "y": 12, "width": 50, "height": 50}, "confidence": 0.9}]
    results = tracker.update(detections)
    assert len(results) == 1
    assert results[0]["track_id"] == track_id_1
    
    # Frame 3: Missing (track should be kept in buffer, but nothing returned)
    detections = []
    results = tracker.update(detections)
    assert len(results) == 0
    assert len(tracker.tracks) == 1
    
    # Frame 4: Returns (still in buffer)
    detections = [{"bbox": {"x": 15, "y": 15, "width": 50, "height": 50}, "confidence": 0.9}]
    results = tracker.update(detections)
    assert len(results) == 1
    assert results[0]["track_id"] == track_id_1
    
    # Frame 5: Missing again
    tracker.update([])
    # Frame 6: Missing again (should exceed track_buffer=2 and be deleted)
    tracker.update([])
    
    # Frame 7: Returns, but buffer expired -> New ID
    detections = [{"bbox": {"x": 15, "y": 15, "width": 50, "height": 50}, "confidence": 0.9}]
    results = tracker.update(detections)
    assert len(results) == 1
    track_id_2 = results[0]["track_id"]
    assert track_id_2 != track_id_1
    assert track_id_2 > 0
