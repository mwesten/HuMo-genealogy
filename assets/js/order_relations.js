/**
 * Oct. 2025 Huub: new script to order relations.
 * Order relations using drag and drop (using jquery and jqueryui).
 */
document.querySelectorAll('.sortable-relations').forEach(function (list) {
    var familyId = list.getAttribute('data-family-id');
    $(list).sortable({
        handle: '.relation-handle'
    }).bind('sortupdate', function () {
        var relationstring = "";
        var handles = list.querySelectorAll('.relation-handle');
        handles.forEach(function (handle, idx) {
            relationstring += handle.id + ";";
            var chldnum = document.getElementById('relationnum' + handle.id);
            if (chldnum) chldnum.innerHTML = (idx + 1);
        });
        relationstring = relationstring.slice(0, -1);
        $.ajax({
            url: "include/drag.php?drag_kind=relations&relstring=" + relationstring + "&family_id=" + familyId,
            success: function (data) { },
            error: function (xhr, ajaxOptions, thrownError) {
                alert(xhr.status);
                alert(thrownError);
            }
        });
    });
});