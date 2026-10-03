const musicBtn = document.getElementById('musicBtn');
const bgMusic = document.getElementById('bg-music');
const popupOverlay = document.getElementById('popupOverlay');
const yesBtn = document.getElementById('yesBtn');
const noBtn = document.getElementById('noBtn');

// Use sessionStorage to ensure the intro resets if she closes the tab
const hasAccepted = sessionStorage.getItem('hasAccepted');

// Handle Pop-up Overlay rendering explicitly
if (popupOverlay) {
    if (hasAccepted === 'true') {
        popupOverlay.style.opacity = '0';
        popupOverlay.style.visibility = 'hidden';
        popupOverlay.style.display = 'none';
    } else {
        popupOverlay.style.opacity = '1';
        popupOverlay.style.visibility = 'visible';
        popupOverlay.style.display = 'flex';
    }
}

// Handle seamless music persistence across sub-pages
if (bgMusic) {
    const musicTime = localStorage.getItem('musicCurrentTime');
    const musicPlaying = localStorage.getItem('musicPlaying');

    if (musicTime) {
        bgMusic.currentTime = parseFloat(musicTime);
    }

    if (musicPlaying === 'true') {
        bgMusic.play()
            .then(() => { if (musicBtn) musicBtn.innerText = "⏸️ Pause Music"; })
            .catch(() => {
                // Browser security feature: requires user interaction before audio plays
                window.addEventListener('click', () => {
                    if (localStorage.getItem('musicPlaying') === 'true' && bgMusic.paused) {
                        bgMusic.play().then(() => { if (musicBtn) musicBtn.innerText = "⏸️ Pause Music"; });
                    }
                }, { once: true });
            });
    }

    // Every half second, save the exact spot of the song
    setInterval(() => {
        if (!bgMusic.paused) {
            localStorage.setItem('musicCurrentTime', bgMusic.currentTime);
        }
    }, 500);
}

// Runaway No Button Loop
noBtn.addEventListener('mouseover', moveNoButton);
noBtn.addEventListener('click', moveNoButton);
noBtn.addEventListener('touchstart', (e) => {
    e.preventDefault();
    moveNoButton();
});

function moveNoButton() {
    const maxMove = window.innerWidth < 600 ? 40 : 70;
    const randomX = Math.floor(Math.random() * (maxMove * 2)) - maxMove;
    const randomY = Math.floor(Math.random() * (maxMove * 2)) - maxMove;
    noBtn.style.transform = `translate(${randomX}px, ${randomY}px)`;
}

// Yes Button Click Actions
yesBtn.addEventListener('click', () => {
    sessionStorage.setItem('hasAccepted', 'true');
    localStorage.setItem('musicPlaying', 'true');

    if (popupOverlay) {
        popupOverlay.style.opacity = '0';
        popupOverlay.style.visibility = 'hidden';
        setTimeout(() => { popupOverlay.style.display = 'none'; }, 400);
    }
    
    createHeartBurst();
    
    if (bgMusic) {
        bgMusic.play()
            .then(() => { if (musicBtn) musicBtn.innerText = "⏸️ Pause Music"; })
            .catch((error) => console.error("Audio playback failed:", error));
    }
});

// Manual Music Button Toggle Control
if (musicBtn && bgMusic) {
    musicBtn.addEventListener('click', () => {
        if (bgMusic.paused) {
            bgMusic.play()
                .then(() => {
                    musicBtn.innerText = "⏸️ Pause Music";
                    localStorage.setItem('musicPlaying', 'true');
                })
                .catch((error) => console.error("Audio playback failed:", error));
        } else {
            bgMusic.pause();
            musicBtn.innerText = "🎵 Play Her Song";
            localStorage.setItem('musicPlaying', 'false');
        }
    });
}

function createHeartBurst() {
    const heartEmojis = ['💖', '💕', '✨', '🌸', '❤️'];
    for (let i = 0; i < 30; i++) {
        const heart = document.createElement('div');
        heart.classList.add('heart-particle');
        heart.innerText = heartEmojis[Math.floor(Math.random() * heartEmojis.length)];
        heart.style.left = Math.random() * 100 + 'vw';
        heart.style.top = '100vh';
        heart.style.setProperty('--random-x', (Math.random() * 200 - 100) + 'px');
        const delay = Math.random() * 2;
        const duration = Math.random() * 2 + 2;
        heart.style.animationDelay = `${delay}s`;
        heart.style.animationDuration = `${duration}s`;
        document.body.appendChild(heart);
        setTimeout(() => { heart.remove(); }, (delay + duration) * 1000);
    }
}

// Relationship Counter Tracker
const startDate = new Date('March 13, 2023 00:00:00').getTime();
function updateCounter() {
    const now = new Date().getTime();
    const difference = now - startDate;
    const days = Math.floor(difference / (1000 * 60 * 60 * 24));
    const hours = Math.floor((difference % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((difference % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((difference % (1000 * 60)) / 1000);
    
    const dEl = document.getElementById('days');
    const hEl = document.getElementById('hours');
    const mEl = document.getElementById('minutes');
    const sEl = document.getElementById('seconds');
    
    if (dEl) dEl.innerText = days < 10 ? '0' + days : days;
    if (hEl) hEl.innerText = hours < 10 ? '0' + hours : hours;
    if (mEl) mEl.innerText = minutes < 10 ? '0' + minutes : minutes;
    if (sEl) sEl.innerText = seconds < 10 ? '0' + seconds : seconds;
}
setInterval(updateCounter, 1000);
updateCounter();