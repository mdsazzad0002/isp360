Vue.component('pos-invoice-preview', {
    props: ['visible', 'showable', 'cart', 'customer', 'sale', 'username'],
    template: `
  <div v-if="visible || showable" class="invoice-overlay">
      <div class="row ms-0 me-0 py-1 d-none print-visible" style="border-radius: 8px;">
        <div class="col-12 ps-0 text-center d-none">
            <img :src="company.logo" style="box-shadow:1px 1px 1px 1px #d9d9d9;border-radius:5px;width: 120px;">
        </div>
        <div class="col-12 pe-0 text-center mt-2">
            <h4 class="m-0">{{company.title}}</h4>
            <address class="m-0"><strong>Mobile: </strong>{{ company.phone }}</address>
            <address class="m-0" v-html="company.address"></address>
        </div>
      </div>

      <div style="display: flex; align-items: center; text-align: center; margin: 0;">
        <div style="flex: 1; border-bottom: 1px solid #000;"></div>
        <div style="padding: 0 15px; font-size: 18px; font-weight: 700;">Order Invoice</div>
        <div style="flex: 1; border-bottom: 1px solid #000;"></div>
      </div>

      <div class="row border border-2 mx-0" style="border-radius: 5px;">
        <div class="col-7">
          <strong style="font-size: 14px;">Customer ID: </strong> <span style="font-size: 13px;" v-text="customer.code ? customer.code : 'Walk-In Customer'"></span><br>
          <span v-if="customer.name != 'Walk In Customer'" style="font-size: 13px;" v-text="customer.name"> <br></span>
          <span style="font-size: 13px;" v-text="customer.phone"></span><br>
          <span style="font-size: 13px;" v-text="customer.address"></span>
        </div>
        <div class="col-5 text-end">
          <strong style="font-size: 13px;">Inv.: </strong> <span style="font-size: 13px;" v-text="sale.invoice"></span><br>
          <strong style="font-size: 13px;">AddBy: </strong> <span style="font-size: 13px;" v-text="username"></span><br>
          <strong style="font-size: 13px;">Date: </strong> <span style="font-size: 13px;" v-text="sale.date"></span>
        </div>
      </div>
      <div style="display: flex; align-items: center; text-align: center; margin: 0;">
        <div style="flex: 1; border-bottom: 1px solid #000;"></div>
        <div style="padding: 0 15px; font-size: 14px; font-weight: 700;">******</div>
        <div style="flex: 1; border-bottom: 1px solid #000;"></div>
      </div>
      <div class="mt-0">
        <table class="table table-bordered" style="border-radius: 5px;border-collapse: collapse;">
          <thead>
            <tr>
              <th style="width:5%;" class="text-center">Sl.</th>
              <th style="width: 55%;" class="text-center">Menu</th>
              <th class="text-center">Quantity</th>
              <th class="text-center">Price</th>
              <th class="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(item, index) in cart">
                <tr>
                    <td class="text-center">{{ index + 1 }}</td>
                    <td colspan="4"> {{ item.name }}-{{ item.code }} </td>
                </tr>
                <tr>
                    <td colspan="2"></td>
                    <td class="text-center">{{ item.quantity }} {{item.unit_name }}</td>
                    <td class="text-end">{{ item.sale_rate }}</td>
                    <td class="text-end">{{ item.total }}</td>
                </tr>
            </template>

            <tr>
              <td colspan="3" style="font-weight: 700;text-align:right;">SubTotal</td>
              <td colspan="2" class="text-end" style="font-weight: 700;" v-text="sale.subtotal"></td>
            </tr>
            <tr>
              <td colspan="3" style="font-weight: 700;text-align:right;">Discount (-)</td>
              <td colspan="2" class="text-end" style="font-weight: 700;" v-text="sale.discount"></td>
            </tr>
            <tr>
              <td colspan="3" style="font-weight: 700;text-align:right;">Total</td>
              <td colspan="2" class="text-end" style="font-weight: 700;" v-text="sale.total"></td>
            </tr>
            <tr>
              <td colspan="3" style="font-weight: 700;text-align:right;">CashPaid</td>
              <td colspan="2" class="text-end" style="font-weight: 700;" v-text="sale.cashPaid"></td>
            </tr>
            <tr>
              <td colspan="3" style="font-weight: 700;text-align:right;">BankPaid</td>
              <td colspan="2" class="text-end" style="font-weight: 700;" v-text="sale.bankPaid"></td>
            </tr>
            <tr style="border-bottom: none;">
              <td colspan="3" style="font-weight: 700;text-align:right;border-bottom: 1px solid #ccc;border-left: 1px solid #ccc:">Due</td>
              <td colspan="2" class="text-end" style="font-weight: 700;border-bottom: 1px solid #ccc;border-left: 1px solid #ccc:border-right: 1px solid #ccc;" v-text="sale.due"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  `,
    watch: {
        visible(newVal) {
            if (newVal) {
                this.$nextTick(() => {
                    this.autoPrint();
                });
            }
        }
    },
    data() {
        return {
            company: {},
            isPrint: null,
        }
    },
    async created() {
        let params = new URLSearchParams(window.location.search);
        this.isPrint = params.get('print');

        await this.getCompany();
    },
    methods: {
        async getCompany() {
            await axios.get('/get-companyProfile')
                .then(res => {
                    this.company = res.data;
                    this.company.logo = this.company.logo ? this.company.logo : '/noImage.jpg';

                    if (this.isPrint != null) {
                        setTimeout(() => {
                            this.autoPrint();
                        }, 1000);
                    }
                })
        },
        withDecimal(n) {
            n = n == undefined ? 0 : parseFloat(n).toFixed(this.fixed);
            var nums = n.toString().split(".");
            var whole = this.convertNumberToWords(nums[0]);
            if (nums.length == 2 && nums[1] > 0) {
                var fraction = this.convertNumberToWords(nums[1]);
                return whole + "& " + fraction + " only";
            } else {
                return whole + " only";
            }
        },
        convertNumberToWords(amount) {
            var words = new Array();
            words[0] = "";
            words[1] = "One";
            words[2] = "Two";
            words[3] = "Three";
            words[4] = "Four";
            words[5] = "Five";
            words[6] = "Six";
            words[7] = "Seven";
            words[8] = "Eight";
            words[9] = "Nine";
            words[10] = "Ten";
            words[11] = "Eleven";
            words[12] = "Twelve";
            words[13] = "Thirteen";
            words[14] = "Fourteen";
            words[15] = "Fifteen";
            words[16] = "Sixteen";
            words[17] = "Seventeen";
            words[18] = "Eighteen";
            words[19] = "Nineteen";
            words[20] = "Twenty";
            words[30] = "Thirty";
            words[40] = "Forty";
            words[50] = "Fifty";
            words[60] = "Sixty";
            words[70] = "Seventy";
            words[80] = "Eighty";
            words[90] = "Ninety";
            amount = amount.toString();
            var atemp = amount.split(".");
            var number = atemp[0].split(",").join("");
            var n_length = number.length;
            var words_string = "";
            if (n_length <= 9) {
                var n_array = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0);
                var received_n_array = new Array();
                for (var i = 0; i < n_length; i++) {
                    received_n_array[i] = number.substr(i, 1);
                }
                for (var i = 9 - n_length, j = 0; i < 9; i++, j++) {
                    n_array[i] = received_n_array[j];
                }
                for (var i = 0, j = 1; i < 9; i++, j++) {
                    if (i == 0 || i == 2 || i == 4 || i == 7) {
                        if (n_array[i] == 1) {
                            n_array[j] = 10 + parseInt(n_array[j]);
                            n_array[i] = 0;
                        }
                    }
                }
                value = "";
                for (var i = 0; i < 9; i++) {
                    if (i == 0 || i == 2 || i == 4 || i == 7) {
                        value = n_array[i] * 10;
                    } else {
                        value = n_array[i];
                    }
                    if (value != 0) {
                        words_string += words[value] + " ";
                    }
                    if (
                        (i == 1 && value != 0) ||
                        (i == 0 && value != 0 && n_array[i + 1] == 0)
                    ) {
                        words_string += "Crores ";
                    }
                    if (
                        (i == 3 && value != 0) ||
                        (i == 2 && value != 0 && n_array[i + 1] == 0)
                    ) {
                        words_string += "Lakhs ";
                    }
                    if (
                        (i == 5 && value != 0) ||
                        (i == 4 && value != 0 && n_array[i + 1] == 0)
                    ) {
                        words_string += "Thousand ";
                    }
                    if (
                        i == 6 &&
                        value != 0 &&
                        n_array[i + 1] != 0 &&
                        n_array[i + 2] != 0
                    ) {
                        words_string += "Hundred and ";
                    } else if (i == 6 && value != 0) {
                        words_string += "Hundred ";
                    }
                }
                words_string = words_string.split("  ").join(" ");
            }
            return words_string;
        },

        async autoPrint() {
            const mediaQuery = window.matchMedia("(min-width: 300px) and (max-width: 1366px)");
            if (!mediaQuery.matches) {
                const selfThis = this;
                const oldTitle = window.document.title;
                window.document.title = "Sale Invoice"
                const printWindow = document.createElement('iframe');
                document.body.appendChild(printWindow);
                printWindow.srcdoc = `
                  <html>
                    <head>
                          <link href="/backend/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
                          <link href="/backend/css/custom.css" rel="stylesheet">
                          <style>
                              .table>:not(caption)>*>* {
                                  font-size: 15px !important;
                              }
                              tr td, tr th{
                                vertical-align: middle !important;
                              }
                              @media print{
                                .print-visible{
                                  display: flex !important;
                                }
                              }                                        
                          </style>
                    </head>
                    <body>
                      <div class="container-fluid">
                          <div class="row">
                              <div class="col-12">
                                  ${document.querySelector('.invoice-overlay').innerHTML}
                              </div>
                          </div>
                      </div>
                    </body>
                  </html>
                `;
                printWindow.onload = async function () {
                    printWindow.contentWindow.focus();
                    await new Promise(resolve => setTimeout(resolve, 500));
                    printWindow.contentWindow.print();
                    document.body.removeChild(printWindow);
                    window.document.title = oldTitle;

                    selfThis.$emit('close');
                    location.href = '/pending-sale-record'
                };
            } else {
                document.querySelector('.print-visible').classList.remove('d-none');
                let mprintWindow = window.open('', 'PRINT', `width=${screen.width}, height=${screen.height}, left=0, top=0`);
                mprintWindow.document.write(`<html>
                    <head>
                          <link href="/backend/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
                          <link href="/backend/css/custom.css" rel="stylesheet">
                          <style>
                              .table>:not(caption)>*>* {
                                  font-size: 15px !important;
                              }
                              tr td, tr th{
                                vertical-align: middle !important;
                              }
                              @media print{
                                @page{
                                    margin: 15px 5px 10px 5px !important;
                                }
                                .print-visible{
                                  display: flex !important;
                                }
                              }                                        
                          </style>
                    </head>
                    <body>
                      <div class="container-fluid">
                          <div class="row">
                              <div class="col-12">
                                  ${document.querySelector('.invoice-overlay').innerHTML}
                              </div>
                          </div>
                      </div>                   
                    </body>
                  </html>`);

                mprintWindow.focus();
                await new Promise(resolve => setTimeout(resolve, 1000));
                mprintWindow.print();
            }
        }
    }
});
