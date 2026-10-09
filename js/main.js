function toggleCollapse(headerEl) {
    headerEl.parentElement.classList.toggle('collapsed');
}

function applyFilters() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase();
    const statusVal = document.getElementById('statusFilter').value;
    const typeVal = document.getElementById('typeFilter').value;
    const scopeVal = document.getElementById('scopeFilter').value;
    document.querySelectorAll('.filterable-section').forEach(section => {
        const sectionType = section.getAttribute('data-type');
        const typeMatches = (typeVal === 'ALL' || typeVal === sectionType);
        if (!typeMatches) {
            section.style.display = 'none';
            return;
        }
        let visibleRowsInSection = 0;
        const rows = section.querySelectorAll('.data-row');
        rows.forEach(row => {
            const rowText = row.innerText.toLowerCase();
            const rowStatus = row.getAttribute('data-status');
            const rowScope = row.getAttribute('data-scope');
            const matchesSearch = rowText.includes(searchVal);
            const matchesStatus = (statusVal === 'ALL' || rowStatus === statusVal);
            const matchesScope = (scopeVal === 'ALL' || scopeVal === rowScope);
            if (matchesSearch && matchesStatus && matchesScope) {
                row.style.display = '';
                visibleRowsInSection++;
            } else {
                row.style.display = 'none';
            }
        });
        section.style.display = visibleRowsInSection > 0 ? '' : 'none';
    });
}

function hlight() {
        
    const textarea = document.getElementById('urls');
    textarea.classList.remove('pulse-attention');

    // Restart the animation if clicked repeatedly.
    void textarea.offsetWidth;

    textarea.classList.add('pulse-attention');

    textarea.addEventListener('animationend', () => {
        textarea.classList.remove('pulse-attention');
    }, { once: true });

    textarea.focus(); // Focus on the textarea

}

function showLoadingState() {
    const mainEl = document.querySelector('main');
    if (mainEl) {
        mainEl.innerHTML = `
            <div class="loading-overlay">
                <div class="spinner"></div>
                <p>Analyzing web resources and building report...</p>
            </div>
        `;
    }
}

function updateReviewStatus(selectEl) {
    const fileHash = selectEl.getAttribute('data-file-hash');
    const itemKey = selectEl.getAttribute('data-item-key');
    const status = selectEl.value;

    const formData = new URLSearchParams();
    formData.append('action', 'update_review');
    formData.append('file_hash', fileHash);
    formData.append('item_key', itemKey);
    formData.append('status', status);

    fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to update review status.');
        } else {
            // Optional: Provide subtle visual feedback (e.g., brief border highlight)
            selectEl.style.borderColor = 'var(--success-color)';
            setTimeout(() => { selectEl.style.borderColor = ''; }, 1000);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Bind to analyzer form submission and sidebar report clicks
document.addEventListener('DOMContentLoaded', () => {
    const analyzerForm = document.getElementById('analyzer-form');
    if (analyzerForm) {
        analyzerForm.addEventListener('submit', () => {
            showLoadingState();
        });
    }

    document.querySelectorAll('.file-item').forEach(item => {
        item.addEventListener('click', () => {
            showLoadingState();
        });
    });
});
