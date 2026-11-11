import * as faceapi from 'face-api.js';

// Real model execution with synthetic descriptors; no employee biometric data.
export async function probeFaceModels() {
  await Promise.all([
    faceapi.nets.ssdMobilenetv1.loadFromUri('/models'),
    faceapi.nets.faceLandmark68Net.loadFromUri('/models'),
    faceapi.nets.faceRecognitionNet.loadFromUri('/models'),
    faceapi.nets.tinyFaceDetector.loadFromUri('/models'),
  ]);
  const stored = JSON.parse(JSON.stringify(Array.from({ length: 128 }, (_, i) => Math.sin(i) / 10)));
  const descriptor = new Float32Array(stored);
  const matcher = new faceapi.FaceMatcher([new faceapi.LabeledFaceDescriptors('existing-worker', [descriptor])], 0.5);
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = 160;
  canvas.getContext('2d').fillRect(0, 0, 160, 160);
  const detection = await faceapi.detectSingleFace(canvas).withFaceLandmarks().withFaceDescriptor();
  return {
    known: matcher.findBestMatch(descriptor).label,
    unknown: matcher.findBestMatch(new Float32Array(128).fill(1)).label,
    emptyFrame: detection === undefined,
  };
}
