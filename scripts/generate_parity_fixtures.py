import os
import sys
import json
import shutil
import subprocess
from PIL import Image

def generate():
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    target_dirs = [
        os.path.join(root, 'tests', 'fixtures', 'parity'),
        os.path.join(root, 'clients', 'edge-engine-rs', 'tests', 'fixtures', 'parity'),
    ]
    for d in target_dirs:
        os.makedirs(d, exist_ok=True)

    sources = [
        'storage/app/private/faces/01a119ed-ba29-71ac-b092-8b8541c294cf.jpg',
        'storage/app/private/faces/01a13863-39f3-7216-9df0-d446e452a107.jpg',
        'storage/app/private/faces/01a13863-39f8-717c-aa40-039087f04ffd.jpg',
        'storage/app/private/faces/01a13863-39fa-72bd-a5a6-84544dc28b48.jpg',
        'storage/app/private/faces/01a13863-39ff-73eb-a06b-bb3ab329379e.jpg',
        'storage/app/private/faces/01a14363-302f-72f3-ba4f-081f6d44b5cf.jpeg',
        'storage/app/private/faces/01a14375-a514-7235-b008-b0790cc21ff6.jpg',
        'storage/app/private/enrollments/photos/photo_01a106e6-54a9-70dd-844c-857831800e97_018641f5-4fe5-4eec-9550-93f7843ea5a2.jpg',
        'storage/app/private/enrollments/photos/photo_01a106e6-54d6-7224-8add-f2dc99474c86_11ee093a-b9f3-47af-bb7d-97b2d427a182.jpg',
        'storage/app/private/enrollments/photos/photo_01a106e6-54d6-7224-8add-f2dc99474c86_352d6aeb-03af-4751-b827-e7fc9fd47c4e.jpg',
        'storage/app/private/enrollments/photos/photo_01a106e6-54d6-7224-8add-f2dc99474c86_83451dc4-2323-49d4-a981-8f46e60e957a.jpg',
        'storage/app/private/enrollments/photos/photo_01a106e6-54d6-7224-8add-f2dc99474c86_ed323e2b-2484-40bc-ac18-91d7dd47a6ef.jpg',
    ]

    extract_py = os.path.join(root, 'packages', 'face_core', 'extract.py')
    detector_model = os.path.join(root, 'models', 'detector.onnx')
    recognizer_model = os.path.join(root, 'models', 'recognizer.onnx')

    items = []
    idx = 1
    for s in sources:
        abs_s = os.path.join(root, s)
        if not os.path.exists(abs_s):
            continue
        ext = os.path.splitext(s)[1].lower()
        base_name = f'parity_face_{idx:02d}{ext}'
        items.append((abs_s, base_name, 'copy'))
        idx += 1

    # Over 3000px fixture (width 3000, height ~3997)
    large_base = f'parity_face_{idx:02d}_large3000px.jpg'
    items.append((os.path.join(root, sources[7]), large_base, 'large'))
    idx += 1

    # Rotated phone shot EXIF orientation 6 (90 CW)
    exif6_base = f'parity_face_{idx:02d}_exif_rot90.jpg'
    items.append((os.path.join(root, sources[1]), exif6_base, 'exif_6'))
    idx += 1

    # Rotated phone shot EXIF orientation 8 (270 CW)
    exif8_base = f'parity_face_{idx:02d}_exif_rot270.jpg'
    items.append((os.path.join(root, sources[2]), exif8_base, 'exif_8'))
    idx += 1

    primary_dir = target_dirs[0]

    for src_path, fname, mode in items:
        dest_img = os.path.join(primary_dir, fname)
        if mode == 'copy':
            shutil.copy2(src_path, dest_img)
        elif mode == 'large':
            im = Image.open(src_path)
            # Upscale preserving aspect ratio to width 3200
            w, h = im.size
            new_w = 3000
            new_h = int(h * (3000 / w))
            im_resized = im.resize((new_w, new_h), Image.Resampling.LANCZOS)
            im_resized.save(dest_img, quality=95)
        elif mode == 'exif_6':
            # Orientation 6 means the camera was rotated 90 CW, so raw pixels are rotated 90 CCW
            im = Image.open(src_path)
            # Rotate raw pixels 90 CCW (270 CW) so when orientation 6 is applied, it returns to normal
            im_rot = im.rotate(90, expand=True) # in PIL, positive angle is CCW
            exif = im_rot.getexif()
            exif[0x0112] = 6
            im_rot.save(dest_img, exif=exif, quality=95)
        elif mode == 'exif_8':
            # Orientation 8 means camera was rotated 270 CW, so raw pixels are rotated 90 CW
            im = Image.open(src_path)
            im_rot = im.rotate(-90, expand=True) # 90 CW
            exif = im_rot.getexif()
            exif[0x0112] = 8
            im_rot.save(dest_img, exif=exif, quality=95)

        # Run Python extract.py to produce the sibling JSON
        cmd = [sys.executable, extract_py, detector_model, recognizer_model, dest_img]
        res = subprocess.run(cmd, capture_output=True, text=True)
        if res.returncode != 0:
            print(f'ERROR on {fname}: {res.stderr}')
            continue

        json_out = res.stdout.strip()
        # Parse to ensure valid JSON
        parsed = json.loads(json_out)
        if not parsed.get('success'):
            print(f'FAILURE on {fname}: {json_out}')
            continue

        stem = os.path.splitext(fname)[0]
        json_path = os.path.join(primary_dir, f'{stem}.json')
        with open(json_path, 'w', encoding='utf-8') as f:
            f.write(json_out)

        # Sync to second directory
        second_dir = target_dirs[1]
        shutil.copy2(dest_img, os.path.join(second_dir, fname))
        shutil.copy2(json_path, os.path.join(second_dir, f'{stem}.json'))

        print(f'Generated fixture {fname} + {stem}.json (embedding length {len(parsed["embedding_b64"])})')

    print(f'Done! Fixtures synced to both target directories.')

if __name__ == '__main__':
    generate()
