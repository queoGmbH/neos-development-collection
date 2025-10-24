(function() {
    'use strict';

    // Content Repository selector - reload the page with selected repository
    window.SystemHealthViews = window.SystemHealthViews || {};
    window.SystemHealthViews.changeContentRepository = function(contentRepositoryId) {
        const currentPath = window.location.pathname;
        const indexUrl = currentPath.replace(/\/(index)?$/, '') + '/index';
        window.location.href = indexUrl + '?contentRepository=' + encodeURIComponent(contentRepositoryId);
    };
})();
