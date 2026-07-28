(() => {
    const answerForm = document.querySelector('#answer-form');
    const nextForm = document.querySelector('#next-form');
    const input = document.querySelector('#label');
    const image = document.querySelector('#captcha-image');
    const status = document.querySelector('#status');
    const savedCount = document.querySelector('#saved-count');
    const submitButton = document.querySelector('#submit-button');
    let busy = false;

    if (!answerForm || !nextForm || !input || !image || !status || !submitButton) {
        return;
    }

    const digitMap = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    };

    const showStatus = (message, isError = false) => {
        status.hidden = false;
        status.textContent = message;
        status.classList.toggle('error', isError);
    };

    const setBusy = value => {
        busy = value;
        submitButton.disabled = value;
        nextForm.querySelector('button').disabled = value;
    };

    const applyChallenge = challenge => {
        if (!challenge) {
            answerForm.hidden = true;
            image.closest('.image-stage').hidden = true;
            nextForm.querySelector('button').textContent = 'Try another captcha';
            return;
        }
        answerForm.hidden = false;
        image.closest('.image-stage').hidden = false;
        nextForm.querySelector('button').textContent = 'New captcha';
        answerForm.elements.challenge_id.value = challenge.id;
        image.src = `${challenge.image_url}&nonce=${Date.now()}`;
        input.value = '';
        input.focus({preventScroll: true});
    };

    const submit = async form => {
        if (busy) return;
        setBusy(true);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {'Accept': 'application/json'},
            });
            let payload;
            try {
                payload = await response.json();
            } catch {
                throw new Error('The server returned an invalid response.');
            }
            if (!response.ok) {
                const problem = new Error(payload.error || 'The request failed.');
                problem.payload = payload;
                throw problem;
            }

            if (savedCount) {
                savedCount.textContent = new Intl.NumberFormat().format(
                    payload.saved_this_session
                );
            }
            applyChallenge(payload.challenge);
            showStatus(
                payload.warning
                    ? `${payload.message} ${payload.warning}`
                    : payload.message,
                Boolean(payload.warning),
            );
        } catch (problem) {
            if (
                problem.payload
                && Object.prototype.hasOwnProperty.call(problem.payload, 'challenge')
            ) {
                applyChallenge(problem.payload.challenge);
            }
            showStatus(problem.message || 'Connection error. Refresh and try again.', true);
            if (!answerForm.hidden) {
                input.focus({preventScroll: true});
                input.select();
            }
        } finally {
            setBusy(false);
        }
    };

    input.addEventListener('input', () => {
        input.value = Array.from(
            input.value,
            character => digitMap[character] ?? character,
        ).join('').replace(/[^0-9]/g, '');
    });

    answerForm.addEventListener('submit', event => {
        event.preventDefault();
        submit(answerForm);
    });
    nextForm.addEventListener('submit', event => {
        event.preventDefault();
        submit(nextForm);
    });

    input.focus();
})();
