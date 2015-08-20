if(typeof(Beans) != "object" || !Beans.id){

    window.beansAsyncInit = function() {

        Beans.onSuccess = function(){
            if(!beans_data.account){
                window.location = window.location.href;
            }
            return false;
        };

        Beans.onLogin = function(){
            window.location = beans_data.login_url;
            return false;
        };

        Beans.init({
            id : beans_data.beans_address,
            default_connect_mode: beans_data.connect_mode,
            domain: beans_data.domain,
            domainAPI: beans_data.domain_api,
            display: beans_data.display
        });

        if(beans_data.authentication && !Beans._session.get('beans_account')){
            (function(el,n,h){
                if(el.addEventListener){el.addEventListener(n,h,false);}else if(el.attachEvent){el.attachEvent('on'+n,h);}}
            )(document, 'DOMContentLoaded', beans_authentify);
        }
    };

    (function(d, s, id){
        var js, fjs = d.getElementsByTagName(s)[0];
        if (d.getElementById(id)) {return;}
        js = d.createElement(s); js.id = id;
        js.src = "//www.trybeans.com/assets/static/js/lib/0.9/beans.js";
        fjs.parentNode.insertBefore(js, fjs);
    }(document, 'script', 'beans-js'));

}else{
    Beans.defaultMode = beans_data.connect_mode;

    if(beans_data.display){
        Beans._loadDisplay();
    }

    if(beans_data.authentication && !Beans._session.get('beans_account')){
        (function(el,n,h){
            if(el.addEventListener){el.addEventListener(n,h,false);}else if(el.attachEvent){el.attachEvent('on'+n,h);}}
        )(document, 'DOMContentLoaded', beans_authentify);
    }
}

function beans_authentify(){
    var url = '//'+beans_data.domain+'/oauth/authentify/?client_id='
        +beans_data.beans_address+'&authentication='+beans_data.authentication
        +'&account='+beans_data.account;
    var frame_id = 'beans_authenticate_frame';
    var f = document.getElementById(frame_id);
    if (f) {f.src = url; Beans.connect(3);}
    f = document.createElement('iframe');
    f.id = frame_id;
    f.name = frame_id;
    f.src = url;
    f.setAttribute('allowtransparency', 'true');
    var s = 'border:none; overflow:hidden; position: fixed; top:-100px; left: -100px;width: 20px; height: 20px';
    f.setAttribute('style', s);
    document.body.appendChild(f);
    Beans.connect(3);
}

function beans_post(path, params){

    var form = document.createElement("form");
    form.setAttribute("method", "post");
    form.setAttribute("action", path);

    for(var key in params) {
        if(params.hasOwnProperty(key)) {
            var hiddenField = document.createElement("input");
            hiddenField.setAttribute("type", "hidden");
            hiddenField.setAttribute("name", key);
            hiddenField.setAttribute("value", params[key]);
            form.appendChild(hiddenField);
        }
    }

    document.body.appendChild(form);
    form.submit();
    return false;
}