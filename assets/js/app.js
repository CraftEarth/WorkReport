document.addEventListener("DOMContentLoaded", () => {

    const textarea =
        document.getElementById("original_text");

    const wordCount =
        document.getElementById("wordCount");

    const charCount =
        document.getElementById("charCount");

    if (!textarea) {
        return;
    }

    function updateCounts() {

        const text = textarea.value.trim();

        const words =
            text === ""
                ? 0
                : text.split(/\s+/).length;

        if (wordCount) {
            wordCount.textContent =
                `${words} ${words === 1 ? "word" : "words"}`;
        }

        if (charCount) {
            charCount.textContent =
                `${textarea.value.length} characters`;
        }
    }

    textarea.addEventListener(
        "input",
        updateCounts
    );

    updateCounts();

});
