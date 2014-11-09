(function(d, s, id){
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {return;}
    js = d.createElement(s); js.id = id;
    js.src = "//www.beans.cards/assets/static/js/oauth.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'beans-connect-js'));


