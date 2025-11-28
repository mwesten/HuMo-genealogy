// Script for history window
(function () {
    const STORAGE_KEY = 'chatGenealogyQuestionHistory';
    const MAX_ITEMS = 50;

    function loadHistory() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            const arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            console.warn('Failed to load question history', e);
            return [];
        }
    }

    function saveHistory(list) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(list.slice(0, MAX_ITEMS)));
        } catch (e) {
            console.warn('Failed to save question history', e);
        }
    }

    function addHistoryItem(question) {
        if (!question) return;
        const normalized = String(question).trim();
        if (!normalized) return;
        const list = loadHistory();
        // Put newest first, avoid duplicates (remove existing)
        const filtered = list.filter(item => item !== normalized);
        filtered.unshift(normalized);
        saveHistory(filtered);
        renderHistory();
    }

    function clearHistory() {
        localStorage.removeItem(STORAGE_KEY);
        renderHistory();
    }

    function renderHistory() {
        const container = document.getElementById('question-history-list');
        if (!container) return;
        const items = loadHistory();
        container.innerHTML = '';
        if (items.length === 0) {
            const el = document.createElement('div');
            el.className = 'text-muted small';
            el.textContent = LABEL_NO_HISTORY;
            container.appendChild(el);
            return;
        }
        items.forEach(q => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action';
            btn.setAttribute('role', 'listitem');
            btn.textContent = q;
            btn.addEventListener('click', function () {
                // fill input and submit
                const input = document.getElementById('question');
                if (!input) return;
                input.value = q;
                input.focus();
                // trigger submit: find the form and submit programmatically
                const form = document.getElementById('chat-form');
                if (form) {
                    // submit event handlers will run
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });
            container.appendChild(btn);
        });
    }

    // Attach handlers after DOM is ready so elements exist
    document.addEventListener('DOMContentLoaded', function () {
        renderHistory();

        // Clear button
        const clearBtn = document.getElementById('qh-clear');
        if (clearBtn) clearBtn.addEventListener('click', clearHistory);

        // Capture submit to record question before other handlers clear it.
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (form && form.id === 'chat-form') {
                const input = form.querySelector('#question');
                if (input) {
                    const q = String(input.value || '').trim();
                    if (q) addHistoryItem(q);
                }
            }
        }, true); // use capture so we run early
    });
})();


// Script for chat window
const CHAT_API_ENDPOINT = 'chat_genealogy_api.php?action=chat';
const form = document.getElementById('chat-form');
const input = document.getElementById('question');
const historyEl = document.getElementById('chat-history');

form.addEventListener('submit', function (e) {
    e.preventDefault();
    const question = input.value.trim();
    if (!question) return;
    appendMessage('user', question);
    input.disabled = true;

    fetch(CHAT_API_ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'question=' + encodeURIComponent(question)
    })
        .then(r => r.json())
        .then(data => appendMessage('assistant', data.answer || '—'))
        .catch(() => appendMessage('assistant', 'Server error. Please try again.'))
        .finally(() => {
            input.value = '';
            input.disabled = false;
            input.focus();
        });
});

function appendMessage(role, content) {
    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex mb-3 ' + (role === 'user' ? 'justify-end' : '');
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble ' + role + (role === 'user' ?
        ' bg-primary text-white shadow-sm' :
        ' bg-light border');

    const label = document.createElement('div');
    label.className = 'small mb-1 ' + (role === 'user' ? 'text-white-50' : 'text-muted');
    label.textContent = role === 'user' ? LABEL_YOU : LABEL_ASSISTANT;

    bubble.appendChild(label);

    const body = document.createElement('div');

    if (role === 'assistant') {
        // Raw HTML from backend (ASSUMED safe). If unsure, sanitize here.
        body.innerHTML = String(content);

        // Show text with typewriter effect (disable the body.innerHTML line). BUT: html style is lost during typing.
        //typewriterEffect(body, String(content));
    } else {
        // User text: keep safe (no HTML)
        const lines = String(content).split(/\r?\n/);
        lines.forEach((line, i) => {
            if (i > 0) body.appendChild(document.createElement('br'));
            body.appendChild(document.createTextNode(line));
        });
    }

    bubble.appendChild(body);
    wrapper.appendChild(bubble);
    historyEl.appendChild(wrapper);

    //historyEl.scrollTop = historyEl.scrollHeight; // Scrolls to bottom

    const scrollAmount = 200; // pixels to scroll
    historyEl.scrollTop += scrollAmount;

    // Test: force scroll to top of last answer after DOM update (but whole screen will scroll)
    //setTimeout(() => {
    //    wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    //}, 100);
}

/*
function typewriterEffect(element, htmlContent, wordDelay = 5) {
    const temp = document.createElement('div');
    temp.innerHTML = htmlContent;
    const textContent = temp.textContent || temp.innerText;

    const words = textContent.split(/(\s+)/);
    let currentIndex = 0;
    let isComplete = false;
    element.innerHTML = '';

    // Click to complete typing instantly
    const clickHandler = () => {
        if (!isComplete) {
            element.innerHTML = htmlContent;
            historyEl.scrollTop = historyEl.scrollHeight;
            isComplete = true;
            element.removeEventListener('click', clickHandler);
        }
    };
    element.addEventListener('click', clickHandler);
    element.style.cursor = 'pointer';
    element.title = 'Click to show full response';

    function typeNextWord() {
        if (isComplete || currentIndex >= words.length) {
            if (!isComplete) {
                element.innerHTML = htmlContent;
                historyEl.scrollTop = historyEl.scrollHeight;
                isComplete = true;
            }
            element.style.cursor = 'default';
            element.title = '';
            element.removeEventListener('click', clickHandler);
            return;
        }

        const word = words[currentIndex];

        if (word.includes('\n')) {
            element.appendChild(document.createElement('br'));
        } else {
            element.appendChild(document.createTextNode(word));
        }

        currentIndex++;
        historyEl.scrollTop = historyEl.scrollHeight;

        setTimeout(typeNextWord, wordDelay);
    }

    typeNextWord();
}
*/