(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.aiCopilotPanel = {
    attach: function (context) {
      once('ai-copilot-init', 'body', context).forEach(function () {
        const settings = drupalSettings.aiCopilot || {};

        // 1. Create floating toggle button
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'copilot-toggle-btn';
        toggleBtn.innerHTML = '⚡ AI Copilot';
        document.body.appendChild(toggleBtn);

        // 2. Create Drawer markup
        const drawer = document.createElement('div');
        drawer.id = 'ai-copilot-drawer';
        drawer.innerHTML = `
          <div class="copilot-header">
            <h3>⚡ Drupal AI Copilot</h3>
            <button id="copilot-close-btn" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:1.2rem;">✕</button>
          </div>
          <div class="copilot-body" id="copilot-conversation">
            <div class="copilot-card">
              <span class="copilot-badge badge-config_only">Ready</span>
              <p style="margin:4px 0;">Developer Mode Active. Enter a site requirement below to evaluate architectural options.</p>
              ${settings.isProduction ? '<p style="color:#f87171;font-size:0.8rem;margin-top:6px;">⚠️ Production Environment: Mutations hard-disabled by default.</p>' : ''}
            </div>
          </div>
          <div class="copilot-footer">
            <button id="copilot-payload-toggle" style="background:none;border:none;color:#38bdf8;cursor:pointer;font-size:0.8rem;text-align:left;">🔍 [View Payload Preview]</button>
            <textarea id="copilot-prompt-input" class="copilot-input" rows="3" placeholder="Describe requirement (e.g., Add a focal point crop to image fields)..."></textarea>
            <div class="copilot-btn-group">
              <button id="copilot-send-btn" class="btn-apply" style="flex:1;">Evaluate Architecture</button>
            </div>
          </div>
        `;
        document.body.appendChild(drawer);

        // Toggle drawer open/close
        toggleBtn.addEventListener('click', () => drawer.classList.add('open'));
        document.getElementById('copilot-close-btn').addEventListener('click', () => drawer.classList.remove('open'));

        // Payload Toggle Event
        let lastPayload = null;
        document.getElementById('copilot-payload-toggle').addEventListener('click', function () {
          if (!lastPayload) {
            alert('No prompt payload generated yet. Enter a prompt and evaluate first.');
            return;
          }
          alert('Outbound LLM Payload:\n' + lastPayload);
        });

        // Send Prompt Event
        document.getElementById('copilot-send-btn').addEventListener('click', async function () {
          const input = document.getElementById('copilot-prompt-input');
          const prompt = input.value.trim();
          if (!prompt) return;

          const conv = document.getElementById('copilot-conversation');
          conv.innerHTML += `<div class="copilot-card" style="border-left:3px solid #38bdf8;"><p><strong>Prompt:</strong> ${prompt}</p></div>`;
          input.value = '';

          try {
            const res = await fetch(settings.chatApiUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken,
                'Accept': 'application/json'
              },
              body: JSON.stringify({ prompt: prompt })
            });

            let data;
            const textResponse = await res.text();
            try {
              data = JSON.parse(textResponse);
            } catch (jsonErr) {
              conv.innerHTML += `<div class="copilot-card" style="border-left:3px solid #ef4444;"><p>HTTP ${res.status} Error: ${textResponse.substring(0, 300)}</p></div>`;
              return;
            }

            if (!res.ok || data.success === false) {
              const errMsg = data.error || data.message || 'Access Denied or Server Error';
              conv.innerHTML += `<div class="copilot-card" style="border-left:3px solid #ef4444;"><p>Error: ${errMsg}</p></div>`;
              return;
            }

            const evalData = data.evaluation;
            lastPayload = data.payload_preview;

            const cardId = 'copilot-card-' + Date.now();
            let cardHtml = `
              <div class="copilot-card" id="${cardId}">
                <span class="copilot-badge badge-${evalData.path}">${evalData.path}</span>
                <p><strong>Reasoning:</strong> ${evalData.reasoning}</p>
                <p><strong>Validation:</strong> ${evalData.validation_status} (${evalData.validation_details})</p>
            `;

            if (evalData.path === 'contrib_patch') {
              cardHtml += `<div class="copilot-pre">${evalData.patch_content}</div>`;
            } else if (evalData.path === 'config_only') {
              cardHtml += `<div class="copilot-pre">${evalData.config_yaml}</div>`;
            } else if (evalData.path === 'custom_code') {
              cardHtml += `<div class="copilot-pre">${evalData.custom_code}</div>`;
            }

            cardHtml += `
                <div class="copilot-btn-group" style="margin-top:10px;">
                  <button class="btn-apply btn-apply-trigger">Apply Changes</button>
                  <button class="btn-secondary btn-edit-trigger">Edit</button>
                </div>
              </div>
            `;

            conv.innerHTML += cardHtml;
            conv.scrollTop = conv.scrollHeight;

            // Attach clean event listener to the newly created card's Apply button
            const newCard = document.getElementById(cardId);
            if (newCard) {
              const applyBtn = newCard.querySelector('.btn-apply-trigger');
              if (applyBtn) {
                applyBtn.addEventListener('click', function () {
                  Drupal.aiCopilotApply(evalData.path, prompt, evalData);
                });
              }
              const editBtn = newCard.querySelector('.btn-edit-trigger');
              if (editBtn) {
                editBtn.addEventListener('click', function () {
                  alert('Edit mode: Modify the configuration YAML or code above before applying.');
                });
              }
            }

          } catch (e) {
            conv.innerHTML += `<div class="copilot-card" style="border-left:3px solid #ef4444;"><p>Error: ${e.message}</p></div>`;
          }
        });

        // Global Apply helper
        Drupal.aiCopilotApply = async function (path, prompt, evalData) {
          if (!confirm('Apply this mutation? A pre-mutation rollback snapshot will be created.')) return;

          try {
            const payload = {
              path: path,
              prompt: prompt,
              config_yaml: (evalData && evalData.config_yaml) ? evalData.config_yaml : '',
              custom_code: (evalData && evalData.custom_code) ? evalData.custom_code : '',
              patch_content: (evalData && evalData.patch_content) ? evalData.patch_content : '',
              module: (evalData && evalData.module) ? evalData.module : ''
            };

            const res = await fetch(settings.applyApiUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken,
                'Accept': 'application/json'
              },
              body: JSON.stringify(payload)
            });

            let data;
            const textResponse = await res.text();
            try {
              data = JSON.parse(textResponse);
            } catch (jsonErr) {
              alert('Apply Failed (HTTP ' + res.status + '): ' + textResponse.substring(0, 300));
              return;
            }

            if (!res.ok || data.success === false) {
              alert('Apply Failed: ' + (data.error || data.message || 'Server error'));
              return;
            }

            alert(data.message + '\nAudit ID: #' + data.audit_id);
            const conv = document.getElementById('copilot-conversation');
            conv.innerHTML += `
              <div class="copilot-card" style="border-left:3px solid #10b981;">
                <p>✅ Mutation Applied (Audit #${data.audit_id}).</p>
                <button class="btn-revert" onclick="Drupal.aiCopilotRevert(${data.audit_id})">Revert Last Change</button>
              </div>
            `;
          } catch (e) {
            alert('Apply error: ' + e.message);
          }
        };

        // Global Revert helper
        Drupal.aiCopilotRevert = async function (auditId) {
          if (!confirm('Revert Mutation #' + auditId + '? Installed modules will be uninstalled and config restored.')) return;

          try {
            const res = await fetch(settings.revertApiUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken,
                'Accept': 'application/json'
              },
              body: JSON.stringify({ audit_id: auditId })
            });

            let data;
            const textResponse = await res.text();
            try {
              data = JSON.parse(textResponse);
            } catch (jsonErr) {
              alert('Revert Failed (HTTP ' + res.status + '): ' + textResponse.substring(0, 300));
              return;
            }

            if (!res.ok || data.success === false) {
              alert('Revert Failed: ' + (data.error || data.message || 'Server error'));
              return;
            }

            alert(data.message);
          } catch (e) {
            alert('Revert error: ' + e.message);
          }
        };

      });
    }
  };

})(Drupal, drupalSettings, once);
