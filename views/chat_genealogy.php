<?php

/**
 * Nov. 2025 Huub: added Chat Genealogy.
 */
?>

<div class="p-2 my-md-2 genealogy_search container">
    <div class="row">
        <!-- Show chat history -->
        <div class="col-2">
            <div id="question-history-panel" aria-label="Question history" title="Question history" style="height:520px;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <strong><?= __('Questions'); ?></strong>
                    <button id="qh-clear" type="button" class="btn btn-sm btn-outline-secondary" aria-label="<?= __('Clear history'); ?>">
                        <?= __('Clear'); ?>
                    </button>
                </div>
                <div id="question-history-list" class="list-group list-group-flush mb-2" role="list"></div>
                <div class="small text-muted">
                    <?= __('Click a question to re-run it.'); ?>
                </div>
            </div>
        </div>

        <!-- Show chat window -->
        <div class="col-10">
            <div class="container">
                <h2 class="mb-2"><?= __('Chat Genealogy'); ?></h2>

                <div id="chat-wrapper" class="border rounded bg-white shadow-sm">
                    <div id="chat-history" class="p-3" style="height:400px; overflow-y:auto;">

                        <div class="d-flex mb-3">
                            <div class="chat-bubble assistant bg-light border">
                                <div class="small text-muted mb-1"><?= __('Assistant'); ?></div>
                                <div>
                                    <?= __('Hi! I can help you search your family tree. Try asking:'); ?>
                                    <ul class="mb-0 ps-3">
                                        <li><?= __('Who was born in Amsterdam in 1850?'); ?></li>
                                        <li><?= __('How many persons are in my tree?'); ?></li>
                                        <li><?= __('Show children of Jan Pietersen'); ?></li>
                                        <li><?= __('Show me the manual'); ?></li>
                                    </ul>

                                    <ul class="mt-3 ps-3">
                                        <?php if ($user['group_edit_trees'] || $user['group_admin'] == 'j'): ?>
                                            <li><b><?= __('Extra options for editors/admins:'); ?></b></li>
                                            <li>Under construction...</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>

                    </div>

                    <form id="chat-form" class="border-top p-3 bg-light">
                        <div class="input-group">
                            <input type="text" id="question" name="question" class="form-control" placeholder="<?= __('Ask a question...'); ?>" autocomplete="off" required>
                            <button type="submit" class="btn btn-primary">
                                <?= __('Send'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // export localized strings to JS, safe via json_encode
    LABEL_YOU = <?= json_encode(__('You'), JSON_UNESCAPED_UNICODE) ?>;
    LABEL_ASSISTANT = <?= json_encode(__('Assistant'), JSON_UNESCAPED_UNICODE) ?>;
    LABEL_NO_HISTORY = <?= json_encode(__('No history yet'), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/chat_genealogy.js"></script>