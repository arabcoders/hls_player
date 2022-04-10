function StreamVideo() {
    let $ = (sel) => document.querySelector(sel);

    let url = $('#url').value;
    let query = new URLSearchParams();

    const $selectParams = ['video', 'video_codec', 'video_preset', 'audio', 'audio_codec'];
    const $inputParams = [
        'video_crf', 'video_level', 'video_profile', 'video_preset',
        'audio_bitrate', 'audio_channels', 'audio_sampling_rate'
    ];

    for (const $elmId of $selectParams) {
        let $elm = $('#' + $elmId);

        let $elmVal = $elm[$elm.selectedIndex].value;
        if ($elmVal) {
            query.append($elmId, $elmVal);
        }
    }

    for (const $elmId of $inputParams) {
        let $elmVal = $('#' + $elmId).value;
        if ($elmVal) {
            query.append($elmId, $elmVal);
        }
    }

    // -- sub params.
    let $elm = $('#subtitle');
    let $subtitle = $elm[$elm.selectedIndex];

    if ('external' === $subtitle.getAttribute('data-kind')) {
        query.append('external', $subtitle.value);
    }

    if ('internal' === $subtitle.getAttribute('data-kind')) {
        query.append('subtitle', $subtitle.value);
    }

    window.location = url + '?' + query.toString();
}
