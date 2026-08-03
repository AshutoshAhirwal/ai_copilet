(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.aiCopilotPanel = {
    attach: function (context) {
      once('ai-copilot-init', 'body', context).forEach(function () {
        var settings = drupalSettings.aiCopilot || {};

        // In-memory conversation ID — intentionally NOT persisted to localStorage
        // so a page reload always starts a fresh conversation.
        var conversationId = generateId();

        // ----------------------------------------------------------------
        // Build DOM
        // ----------------------------------------------------------------

        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'copilot-toggle-btn';
        toggleBtn.setAttribute('aria-label', 'Open AI Copilot');
        toggleBtn.innerHTML = '&#9889; AI Copilot';
        document.body.appendChild(toggleBtn);

        var drawer = document.createElement('div');
        drawer.id = 'ai-copilot-drawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-label', 'AI Copilot chat');
        drawer.innerHTML = buildDrawerHTML(settings);
        document.body.appendChild(drawer);

        var convEl = drawer.querySelector('#copilot-conversation');
        var inputEl = drawer.querySelector('#copilot-prompt-input');
        var sendBtn = drawer.querySelector('#copilot-send-btn');

        // ----------------------------------------------------------------
        // Welcome message
        // ----------------------------------------------------------------
        appendMessage('ai', 'Hello! I\'m Drupal AI Copilot. Describe a site requirement and I\'ll research the best architectural approach using your live site configuration and the contrib module database.');

        // ----------------------------------------------------------------
        // Toggle open / close
        // ----------------------------------------------------------------
        toggleBtn.addEventListener('click', function () {
          drawer.classList.add('open');
          inputEl.focus();
        });

        drawer.querySelector('#copilot-close-btn').addEventListener('click', function () {
          drawer.classList.remove('open');
        });

        // ----------------------------------------------------------------
        // New Chat button — clears server-side history and resets UI
        // ----------------------------------------------------------------
        drawer.querySelector('#copilot-new-chat-btn').addEventListener('click', function () {
          if (!confirm('Start a new conversation? The current conversation history will be cleared.')) return;
          clearSession(conversationId, settings, function () {
            conversationId = generateId();
            convEl.innerHTML = '';
            appendMessage('ai', 'New conversation started. What would you like to build?');
          });
        });

        // ----------------------------------------------------------------
        // Enter key to send (Shift+Enter for newline)
        // ----------------------------------------------------------------
        inputEl.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendBtn.click();
          }
        });

        // ----------------------------------------------------------------
        // Send button
        // ----------------------------------------------------------------
        sendBtn.addEventListener('click', function () {
          var prompt = inputEl.value.trim();
          if (!prompt || sendBtn.disabled) return;

          inputEl.value = '';
          sendBtn.disabled = true;
          sendBtn.textContent = 'Thinking...';

          appendMessage('user', escapeHtml(prompt));
          var thinkingEl = appendThinking();

          var payload = JSON.stringify({ prompt: prompt, conversation_id: conversationId });

          fetch(settings.chatApiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': settings.csrfToken,
              'Accept': 'application/json'
            },
            body: payload
          })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            thinkingEl.remove();

            if (data.error) {
              appendMessage('error', escapeHtml(data.message || 'An error occurred.'));
            } else {
              appendAgentReply(data.reply || '', data.steps || [], data.conversation_id || conversationId, !!data.demo_mode);
              // Update conversation ID in case the server assigned one.
              if (data.conversation_id) conversationId = data.conversation_id;
            }
          })
          .catch(function (err) {
            thinkingEl.remove();
            appendMessage('error', 'Network error: ' + escapeHtml(err.message));
          })
          .finally(function () {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send';
            inputEl.focus();
          });
        });

        // ================================================================
        // DOM helpers
        // ================================================================

        function buildDrawerHTML(cfg) {
          var prodWarn = cfg.isProduction
            ? '<div class="copilot-prod-warn">&#9888; Production — mutations disabled by default</div>'
            : '';
          return ''
            + '<div class="copilot-header">'
            +   '<h3 class="copilot-header-title">&#9889; Drupal AI Copilot</h3>'
            +   '<div class="copilot-header-actions">'
            +     '<button id="copilot-new-chat-btn" class="copilot-btn-icon" title="New conversation">New Chat</button>'
            +     '<button id="copilot-close-btn" class="copilot-close-btn" title="Close">&times;</button>'
            +   '</div>'
            + '</div>'
            + '<div class="copilot-conversation" id="copilot-conversation"></div>'
            + '<div class="copilot-footer">'
            +   prodWarn
            +   '<div class="copilot-input-row">'
            +     '<textarea id="copilot-prompt-input" class="copilot-input" rows="2"'
            +       ' placeholder="Describe a requirement... (Enter to send, Shift+Enter for newline)"></textarea>'
            +     '<button id="copilot-send-btn" class="copilot-send-btn">Send</button>'
            +   '</div>'
            + '</div>';
        }

        function appendMessage(type, htmlContent) {
          var wrap = document.createElement('div');
          wrap.className = 'chat-msg chat-msg--' + type;

          var label = document.createElement('div');
          label.className = 'chat-msg__label';
          label.textContent = type === 'user' ? 'You' : (type === 'error' ? 'Error' : 'AI Copilot');

          var bubble = document.createElement('div');
          bubble.className = 'chat-msg__bubble';
          bubble.innerHTML = htmlContent;

          wrap.appendChild(label);
          wrap.appendChild(bubble);
          convEl.appendChild(wrap);
          scrollDown();
          return wrap;
        }

        function appendThinking() {
          var wrap = document.createElement('div');
          wrap.className = 'chat-msg chat-msg--thinking';

          var label = document.createElement('div');
          label.className = 'chat-msg__label';
          label.textContent = 'AI Copilot';

          var bubble = document.createElement('div');
          bubble.className = 'chat-msg__bubble';
          bubble.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div>';

          wrap.appendChild(label);
          wrap.appendChild(bubble);
          convEl.appendChild(wrap);
          scrollDown();
          return wrap;
        }

        function appendAgentReply(replyText, steps, convId, demoMode) {
          var wrap = document.createElement('div');
          wrap.className = 'chat-msg chat-msg--ai';

          var label = document.createElement('div');
          label.className = 'chat-msg__label';
          label.textContent = 'AI Copilot';

          var bubble = document.createElement('div');
          bubble.className = 'chat-msg__bubble';

          // No LLM provider/key is configured - this reply is a template,
          // not a real model response. Label it clearly rather than let it
          // pass as a genuine answer.
          if (demoMode) {
            var demoBadge = document.createElement('div');
            demoBadge.className = 'copilot-demo-badge';
            demoBadge.textContent = '⚠ Demo Mode — no LLM provider configured. This is a template response, not real AI output. Configure a provider in AI Copilot Settings.';
            bubble.appendChild(demoBadge);
          }

          // Steps trace (collapsed toggle) — shown only if tools were actually called.
          if (steps && steps.length > 0) {
            var stepsId = 'steps-' + Date.now();
            var stepsHtml = '<div class="steps-trace">'
              + '<button class="steps-toggle" onclick="document.getElementById(\'' + stepsId + '\').classList.toggle(\'open\')">'
              + '&#128269; ' + steps.length + ' step' + (steps.length > 1 ? 's' : '') + ' taken &#9656;'
              + '</button>'
              + '<ul class="steps-list" id="' + stepsId + '">';
            steps.forEach(function (s) {
              var icon = s.status === 'completed' ? '&#10003;' : '&#9888;';
              var label = toolLabel(s.tool);
              stepsHtml += '<li class="steps-item steps-item--' + s.status + '">'
                + icon + ' ' + label
                + '</li>';
            });
            stepsHtml += '</ul></div>';
            bubble.innerHTML = stepsHtml;
          }

          // Render the reply text — detect code blocks for syntax highlighting hint.
          var replyDiv = document.createElement('div');
          replyDiv.className = 'reply-text';
          replyDiv.innerHTML = formatReply(replyText);
          bubble.appendChild(replyDiv);

          // If the reply contains a YAML/patch/code recommendation, add Apply button.
          var applySection = extractApplySection(replyText);
          if (applySection) {
            var applyBar = buildApplyBar(applySection, replyText);
            bubble.appendChild(applyBar);
          }

          wrap.appendChild(label);
          wrap.appendChild(bubble);
          convEl.appendChild(wrap);
          scrollDown();
        }

        function toolLabel(name) {
          var labels = {
            'get_site_context': 'Read site configuration',
            'search_contrib_modules': 'Searched contrib modules'
          };
          return labels[name] || name;
        }

        function formatReply(text) {
          // Convert markdown-style code blocks to <pre><code> and escape other content.
          var parts = text.split(/(```[\s\S]*?```)/g);
          return parts.map(function (part) {
            if (part.startsWith('```')) {
              var inner = part.replace(/^```[^\n]*\n?/, '').replace(/```$/, '');
              return '<pre class="result-code">' + escapeHtml(inner) + '</pre>';
            }
            return escapeHtml(part).replace(/\n/g, '<br>');
          }).join('');
        }

        // Detect if the reply text contains YAML or code that could be applied.
        function extractApplySection(text) {
          if (!text) return null;
          var yamlMatch = text.match(/```ya?ml\n([\s\S]*?)```/i);
          if (yamlMatch) return { type: 'config_yaml', content: yamlMatch[1].trim() };
          var phpMatch = text.match(/```php\n([\s\S]*?)```/i);
          if (phpMatch) return { type: 'custom_code', content: phpMatch[1].trim() };
          var patchMatch = text.match(/```(?:diff|patch)\n([\s\S]*?)```/i);
          if (patchMatch) return { type: 'patch', content: patchMatch[1].trim() };
          return null;
        }

        function buildApplyBar(applySection, fullReply) {
          var bar = document.createElement('div');
          bar.className = 'result-card__actions';

          var applyBtn = document.createElement('button');
          applyBtn.className = 'btn-apply';
          applyBtn.textContent = 'Apply Changes';
          applyBtn.addEventListener('click', function () {
            doApply(applySection, fullReply, bar);
          });

          bar.appendChild(applyBtn);
          return bar;
        }

        function doApply(section, fullReply, bar) {
          if (!confirm('Apply this change? A pre-mutation rollback snapshot will be created.')) return;

          var payload = {
            prompt: fullReply.substring(0, 200),
            path: section.type === 'config_yaml' ? 'config_only'
              : section.type === 'custom_code' ? 'custom_code'
              : 'contrib_patch',
            config_yaml: section.type === 'config_yaml' ? section.content : '',
            custom_code: section.type === 'custom_code' ? section.content : '',
            patch_content: section.type === 'patch' ? section.content : '',
            module: ''
          };

          fetch(settings.applyApiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': settings.csrfToken,
              'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success === false || data.error) {
              appendMessage('error', 'Apply failed: ' + escapeHtml(data.error || data.message || 'Unknown error'));
            } else {
              var auditId = data.audit_id;
              var successHtml = '<div class="applied-card">'
                + '<span class="applied-card__text">&#10003; Applied — Audit #' + auditId + '</span>'
                + '<button class="btn-revert" onclick="Drupal.aiCopilotRevert(' + auditId + ')">Revert</button>'
                + '</div>';
              appendMessage('ai', successHtml);
              bar.remove();
            }
          })
          .catch(function (err) {
            appendMessage('error', 'Apply error: ' + escapeHtml(err.message));
          });
        }

        // ================================================================
        // Global revert helper (called from inline onclick above)
        // ================================================================
        Drupal.aiCopilotRevert = function (auditId) {
          if (!confirm('Revert mutation #' + auditId + '? Config and module state will be restored.')) return;

          fetch(settings.revertApiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': settings.csrfToken,
              'Accept': 'application/json'
            },
            body: JSON.stringify({ audit_id: auditId })
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success === false || data.error) {
              appendMessage('error', 'Revert failed: ' + escapeHtml(data.error || data.message || ''));
            } else {
              appendMessage('ai', '&#10003; ' + escapeHtml(data.message || 'Reverted successfully.'));
            }
          })
          .catch(function (err) {
            appendMessage('error', 'Revert error: ' + escapeHtml(err.message));
          });
        };

        // ================================================================
        // Utility helpers
        // ================================================================

        function scrollDown() {
          convEl.scrollTop = convEl.scrollHeight;
        }

        function escapeHtml(str) {
          return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        }

        function generateId() {
          return 'xxxxxxxxxxxxxxxx'.replace(/x/g, function () {
            return (Math.random() * 16 | 0).toString(16);
          });
        }

        function clearSession(id, cfg, callback) {
          fetch(cfg.clearSessionApiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': cfg.csrfToken,
              'Accept': 'application/json'
            },
            body: JSON.stringify({ conversation_id: id })
          })
          .then(callback)
          .catch(callback);
        }

      });
    }
  };

})(Drupal, drupalSettings, once);
