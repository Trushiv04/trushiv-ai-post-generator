( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        var generateBtn = document.getElementById( 'btap_generate_btn' );
        if ( ! generateBtn || typeof btapData === 'undefined' ) {
            return;
        }

        var i18n = btapData.i18n || {};

        generateBtn.addEventListener( 'click', function () {
            var topicInput = document.getElementById( 'btap_topic' );
            var topic = topicInput ? topicInput.value.trim() : '';

            if ( ! topic ) {
                window.alert( i18n.emptyTopic || 'Please type a topic!' );
                return;
            }

            var btn = generateBtn;
            var progress = document.getElementById( 'btap_progress' );
            var result = document.getElementById( 'btap_result' );
            var step = document.getElementById( 'btap_step' );
            var bar = document.getElementById( 'btap_bar' );

            btn.disabled = true;
            btn.textContent = i18n.generating || 'Generating...';
            progress.style.display = 'block';
            result.style.display = 'none';
            bar.classList.remove( 'is-error' );

            var steps = [
                { msg: i18n.stepContent || 'Generating content...', pct: 20 },
                { msg: i18n.stepImage || 'Generating image...', pct: 50 },
                { msg: i18n.stepUpload || 'Uploading image...', pct: 75 },
                { msg: i18n.stepPublish || 'Publishing post...', pct: 90 }
            ];

            var i = 0;
            var ticker = window.setInterval( function () {
                if ( i < steps.length ) {
                    step.textContent = steps[ i ].msg;
                    bar.style.width = steps[ i ].pct + '%';
                    i++;
                }
            }, 8000 );

            var formData = new FormData();
            formData.append( 'action', 'btap_generate_post' );
            formData.append( 'nonce', btapData.nonce );
            formData.append( 'topic', topic );

            fetch( btapData.ajaxUrl, {
                method: 'POST',
                body: formData
            } )
                .then( function ( r ) {
                    return r.json();
                } )
                .then( function ( data ) {
                    window.clearInterval( ticker );
                    bar.style.width = '100%';

                    if ( data.success ) {
                        step.textContent = i18n.ready || 'Post ready!';
                        result.style.display = 'block';
                        result.innerHTML = buildSuccessMarkup( data.data, i18n );
                    } else {
                        step.textContent = i18n.error || 'Error';
                        bar.classList.add( 'is-error' );
                        result.style.display = 'block';
                        result.innerHTML = buildErrorMarkup( data.data && data.data.message ? data.data.message : '' );
                    }
                } )
                .catch( function ( err ) {
                    window.clearInterval( ticker );
                    step.textContent = i18n.networkError || 'Network Error';
                    result.style.display = 'block';
                    result.innerHTML = buildErrorMarkup( err.message );
                } )
                .finally( function () {
                    btn.disabled = false;
                    btn.textContent = i18n.generateBtn || 'Generate & Publish Post';
                } );
        } );

        function buildSuccessMarkup( data, i18n ) {
            var wrapper = document.createElement( 'div' );
            wrapper.className = 'btap-result-success';

            var heading = document.createElement( 'h3' );
            heading.textContent = '✅ ' + ( i18n.success || 'Post Successfully Created!' );
            wrapper.appendChild( heading );

            var titleP = document.createElement( 'p' );
            titleP.textContent = ( i18n.titleLabel || 'Title:' ) + ' ' + data.title;
            wrapper.appendChild( titleP );

            var statusP = document.createElement( 'p' );
            statusP.textContent = ( i18n.statusLabel || 'Status:' ) + ' ' + data.status;
            wrapper.appendChild( statusP );

            var actions = document.createElement( 'div' );
            actions.className = 'btap-result-actions';

            var editLink = document.createElement( 'a' );
            editLink.href = data.edit_url;
            editLink.className = 'button button-primary';
            editLink.target = '_blank';
            editLink.rel = 'noopener noreferrer';
            editLink.textContent = i18n.editPost || 'Edit Post';
            actions.appendChild( editLink );

            actions.appendChild( document.createTextNode( ' ' ) );

            var viewLink = document.createElement( 'a' );
            viewLink.href = data.view_url;
            viewLink.className = 'button';
            viewLink.target = '_blank';
            viewLink.rel = 'noopener noreferrer';
            viewLink.textContent = i18n.viewPost || 'View Post';
            actions.appendChild( viewLink );

            wrapper.appendChild( actions );

            return wrapper.outerHTML;
        }

        function buildErrorMarkup( message ) {
            var wrapper = document.createElement( 'div' );
            wrapper.className = 'btap-result-error';

            var strong = document.createElement( 'strong' );
            strong.textContent = '❌ ';
            wrapper.appendChild( strong );
            wrapper.appendChild( document.createTextNode( message || '' ) );

            return wrapper.outerHTML;
        }
    } );
} )();
