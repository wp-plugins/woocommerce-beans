(function(d, s, id){
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {return;}
    js = d.createElement(s); js.id = id;
    js.src = "//www.beans.cards/assets/static/js/oauth.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'beans-connect-js'));
window.beansAsyncInit = function() {
    Beans.init({
         id : beans_data.public_key
    });
    Beans.onSuccess = function(){
        window.location = window.location.href;
    };
};

function beans_show_msg(){
    msg = beans_data.modal_msg;
    card_name = beans_data.card_name;
    // Get cookie
	var gc = function(k){return(document.cookie.match('(^|; )'+k+'=([^;]*)')||0)[2];};
    // Set cookie
    var sc = function(n,v,d){document.cookie=n+"="+v+"; expires="+(new Date(Date.now()+86400000*d)).toGMTString();};
    // Append a class
	var ac = function(e,c) {if(e.classList)e.classList.add(c);else e.className+=' '+c;};
    // Ge the modal
    var get_modal = function(){

        var modal = document.getElementById('beans_modal');
        if (modal) return modal;

        var container =  document.createElement("div");
        container.id = 'beans_modal_container';
        container.onclick = function(){this.style.display='none';};
        ac(container,'beans-modal-container');

        var table =  document.createElement("div");
        ac(table,'beans-modal-table');

        var cell =  document.createElement("div");
        ac(cell,'beans-modal-table-cell');

        modal =  document.createElement("div");
        modal.id = 'beans_modal';
        modal.onclick =function(e){e.stopPropagation();};
        ac(modal,'beans-modal');

        cell.appendChild(modal);
        table.appendChild(cell);
        container.appendChild(table);

        document.body.appendChild(container);
        return modal;
    };
    // Show modal message
    var show_msg = function (href, msg){

        var cancel = document.createElement('a');
        cancel.innerHTML = 'No';
        cancel.onclick = function (){ document.getElementById('beans_modal_container').style.display = 'none'; };
        cancel.href = '#';
        ac(cancel,'button');
        cancel.style.margin = '5px';

        var accept = document.createElement('a');
        accept.innerHTML = 'Yes';
        accept.href = href;
        accept.target = '_blank';
        ac(accept,'button');
        accept.style.margin = '5px';

        var div_msg = document.createElement('div');
        div_msg.innerHTML = msg;
        div_msg.style.margin = '10px 10px 25px';

        var modal = get_modal();
        modal.style.padding = '20px';
        modal.style.maxWidth = '350px';
        modal.appendChild(div_msg);
        modal.appendChild(cancel);
        modal.appendChild(accept);
        document.getElementById('beans_modal_container').style.display = 'block';
    };

	if(gc('_beans_mm') || !msg ) return;
	show_msg('//www.beans.cards/$'+card_name, msg);
	sc('_beans_mm',1,30);

}

(function(el,n,h){
    if(el.addEventListener){el.addEventListener(n,h,false);}else if(el.attachEvent){el.attachEvent('on'+n,h);}}
)(document, 'DOMContentLoaded', beans_show_msg);


