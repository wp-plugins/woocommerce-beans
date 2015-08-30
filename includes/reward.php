

<div class="beans-section">

  <script src="//<?php echo BEANS_WEBSITE; ?>/assets/static/js/lib/0.9/beans.js" id="beans-js"></script>

  <script>
    Beans.init({
      id: '<?php echo $beans_address; ?>',
      domain: '<?php echo BEANS_WEBSITE; ?>',
      domainAPI: '<?php echo BEANS_API_WEBSITE; ?>'
    });
  </script>

  <div id="beans-block-intro" style="display: none;">
    <div class="beans-intro">
      <a href="#" onclick="return Beans.connect();">Login or Register</a> to join our customer reward program.
    </div>
  </div>

  <div id="beans-block-balance" style="display: none;">
    <h2>Balance</h2>
    <div class="beans-balance">You have <span id="beans-account-balance"></span>.</div>
  </div>

  <div id="beans-block-rules" style="display: none;">
    <h2>Rules</h2>
    <ul id="beans-rule-list" class="beans-rules">
    </ul>
  </div>

  <div id="beans-block-history" style="display: none;">
    <h2>History</h2>
    <table id='beans-history' class="beans-history">
      <thead>
      <tr>
        <th class="beans-history-date">Date</th>
        <th class="beans-history-description">Description</th>
        <th class="beans-history-beans"></th>
      </tr>
      </thead>
      <tbody id='beans-history-body'>
      <tr></tr>
      </tbody>
    </table>
  </div>

  <script>
    var beans_card;

    var print_beans = function(beans){
      return "<span class='beans-unit' style='color:"+ beans_card.style.secondary_color+";'>" +
      Math.round(beans) + " "+ beans_card.beans_name + "</span>";
    };

    var display_beans_info = function() {

      // Rules
      var beans_rules_list = document.getElementById('beans-rule-list');
      function insert_rule(rule) {
        var line = document.createElement("li");
        line.innerHTML = rule.statement;
        beans_rules_list.appendChild(line);
      }
      function create_rules_list(rules) {
        rules.map(function(rule){
          Beans.get({
            method: 'rule/'+rule.id,
            success: insert_rule
          })
        })
      }
      Beans.get({
        method: 'rule',
        success: create_rules_list
      });

      // History & Balance
      function display_balance_history(){

        // Account Balance
        Beans.get({
          method: 'account/[current]',
          success: function (account) {
            document.getElementById('beans-account-balance').innerHTML = print_beans(account.beans);
          }
        });

        // Account History
        var beans_history_table = document.getElementById('beans-history-body');

        function insert_history_record(record) {
          var line = document.createElement("tr");
          var date = new Date(record.date_created);
          line.innerHTML = '<td class="beans-history-date">' + date.toLocaleDateString() + '</td> ' +
          '<td class="beans-history-description">'  + record.description + '</td> ' +
          '<td class="beans-history-beans">' + print_beans(record.delta) + '</td>';
          beans_history_table.appendChild(line);
        }

        function create_history_table(records) {
          records=records.sort(function(a, b){
            var d1=new Date(a.date_created);
            var d2=new Date(b.date_created);
            return d2-d1;
          }).slice(0,10);
          records.map(insert_history_record)
        }

        Beans.get({
          method: 'account/[current]/history',
          success: create_history_table
        });

      }

      if(Beans._session.get('current_account')){
        display_balance_history();
        document.getElementById('beans-block-intro').style.display = 'none';
        document.getElementById('beans-block-balance').style.display = 'block';
        document.getElementById('beans-block-rules').style.display = 'block';
        document.getElementById('beans-block-history').style.display = 'block';
      }
      else{
        document.getElementById('beans-block-intro').style.display = 'block';
        document.getElementById('beans-block-balance').style.display = 'none';
        document.getElementById('beans-block-rules').style.display = 'block';
        document.getElementById('beans-block-history').style.display = 'none';
      }

    };

    Beans.get({
      method: 'card/<?php echo $beans_address; ?>',
      success: function(data){
        beans_card=data;
        display_beans_info();
      }
    });

  </script>

  <div style="margin-top: 30px">
    <a href="//<?php echo BEANS_WEBSITE.'/'.$beans_address; ?>" target="_blank">Find out more on our Beans page.</a>
  </div>

</div>