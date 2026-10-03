/**
 * صوتٌ قصير مع كل مسحة: الموظّف عند العدّاد ينظر إلى الطرد لا إلى الشاشة.
 * نغمةٌ عالية قصيرة لما قُبل، ومنخفضة أطول لما رُفض — فيرفع رأسه للرسالة.
 */
let audio;

export function beep(ok = true) {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();

        const tone = audio.createOscillator();
        const gain = audio.createGain();

        tone.type = ok ? 'sine' : 'square';
        tone.frequency.value = ok ? 1046 : 220;
        gain.gain.value = 0.06;

        tone.connect(gain).connect(audio.destination);
        tone.start();
        tone.stop(audio.currentTime + (ok ? 0.08 : 0.35));
    } catch {
        // بلا صوت: الرسالة على الشاشة تكفي
    }
}
