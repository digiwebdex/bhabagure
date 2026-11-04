/** False where the browser can't record (or the page isn't on https), and the reply box then offers no microphone. */
export const canRecordVoice = () => typeof MediaRecorder !== 'undefined' && !!navigator.mediaDevices?.getUserMedia
